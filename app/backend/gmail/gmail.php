<?php
// BackendGmail: Z-Push backend using Gmail API (see README.md for mapping decisions).
// Extends BackendDiff; framework Sync uses GetMessageList + StatMessage (Diff), NOT GetChanges
// (Z-Push has no GetChanges interface; Task 5's GetChanges is an internal history helper).
require_once("backend/gmail/config.php");
require_once("backend/gmail/gmail_api.php");

class BackendGmail extends BackendDiff {
    private $api;
    private $username;
    private $domain;
    private $folderCache;
    private $watchedFolders = [];
    private $metaCache = [];

    // Gmail probe, extracted for testability (quota tests stub this instead of hitting Gmail).
    protected function probeLabels() {
        return $this->api->listLabels();
    }

    // Transient Gmail errors (quota / rate limit) must NOT look like a password
    // failure: iOS turns any Logon 401 into a password prompt loop. 503 with
    // Retry-After makes the client back off silently instead.
    private function isTransientGmailError($e) {
        if (!($e instanceof GmailApiException)) return false;
        if ($e->httpStatus === 429) return true;
        if ($e->httpStatus === 403 && stripos($e->getMessage(), 'Quota exceeded') !== false) return true;
        return false;
    }

    // Cache Logon probe labels into folderCache (same filtering as loadLabels),
    // so loadLabels() in the same request reuses them instead of calling
    // listLabels a second time. Cache is per-request only (new PHP process per
    // HTTP request), so label create/rename/delete is fresh on the next request.
    private function cacheLabelsFromProbe($result) {
        $out = [];
        foreach (($result['labels'] ?? []) as $label) {
            $id = $label['id'] ?? '';
            if ($id === '' || $this->isSkippedLabel($id)) continue;
            $out[$id] = $label;
        }
        $this->folderCache = $out;
    }

    public function Logon($username, $domain, $password) {
        try {
            // Local device-password check FIRST when IMAP probe is disabled:
            // wrong-password requests (e.g. iOS OPTIONS probes with a stale
            // cached password) must fail fast without consuming Gmail quota.
            if (!GMAIL_LOGON_IMAP_PROBE) {
                $hash = defined('GMAIL_TEST_DEVICE_PASSWORD_HASH') ? GMAIL_TEST_DEVICE_PASSWORD_HASH : '';
                if ($hash === '' || !password_verify($password, $hash)) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGmail->Logon(): test device password mismatch for user '%s'", $username));
                    return false;
                }
            }
            $this->api = new GmailApiClient(GMAIL_TOKEN_FILE, GMAIL_CLIENT_FILE);
            $labels = $this->probeLabels();
            if (!isset($labels['labels']) || !is_array($labels['labels'])) {
                ZLog::Write(LOGLEVEL_ERROR, "BackendGmail->Logon(): labels.list probe returned no labels");
                return false;
            }
            if (GMAIL_LOGON_IMAP_PROBE) {
                if (!function_exists("imap_open")) {
                    ZLog::Write(LOGLEVEL_ERROR, "BackendGmail->Logon(): php-imap module is not installed, IMAP probe cannot run");
                    return false;
                }
                $server = "{" . GMAIL_IMAP_SERVER . ":" . GMAIL_IMAP_PORT . "/imap" . GMAIL_IMAP_OPTIONS . "}";
                $mbox = @imap_open($server, $username, $password, OP_HALFOPEN);
                if ($mbox) {
                    imap_close($mbox);
                } else {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGmail->Logon(): IMAP probe failed for user '%s': %s", $username, imap_last_error()));
                    return false;
                }
            } else {
                // Device password already verified above (fail closed).
            }
            $this->username = $username;
            $this->domain = $domain;
            $this->metaCache = [];
            $this->cacheLabelsFromProbe($labels);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->Logon(): User '%s' authenticated (labels probe ok, device auth %s)", $username, GMAIL_LOGON_IMAP_PROBE ? "IMAP probe ok" : "test hash ok"));
            return true;
        } catch (Exception $e) {
            if ($this->isTransientGmailError($e)) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGmail->Logon(): transient Gmail quota/rate-limit, returning 503 not 401: %s", $e->getMessage()));
                throw new ServiceUnavailableException("BackendGmail->Logon(): Gmail quota exceeded, retry later");
            }
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendGmail->Logon(): failed: %s", $e->getMessage()));
            return false;
        }
    }

    public function Logoff() {
        $this->api = null;
        $this->folderCache = null;
        $this->metaCache = [];
        return true;
    }

    protected function ensureApi() {
        if ($this->api === null) {
            throw new Exception("BackendGmail: not logged on");
        }
        return $this->api;
    }

    private function isSkippedLabel($id) {
        if (in_array($id, GMAIL_SKIP_LABELS, true)) {
            return true;
        }
        foreach (GMAIL_SKIP_LABEL_PREFIXES as $prefix) {
            if (strpos($id, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private function loadLabels() {
        if ($this->folderCache !== null) {
            return $this->folderCache;
        }
        $result = $this->ensureApi()->listLabels();
        $out = [];
        foreach (($result['labels'] ?? []) as $label) {
            $id = $label['id'] ?? '';
            if ($id === '') {
                continue;
            }
            if ($this->isSkippedLabel($id)) {
                continue;
            }
            $out[$id] = $label;
        }
        $this->folderCache = $out;
        return $out;
    }

    public function GetFolderList() {
        $labels = $this->loadLabels();
        $order = ['INBOX', 'SENT', 'DRAFT', 'TRASH', 'SPAM'];
        $ids = [];
        foreach ($order as $sysId) {
            if (isset($labels[$sysId])) {
                $ids[] = $sysId;
            }
        }
        $ids[] = GMAIL_ALLMAIL_ID;
        $userLabels = [];
        foreach ($labels as $id => $label) {
            if (!in_array($id, $order, true)) {
                $userLabels[] = $label;
            }
        }
        usort($userLabels, function($a, $b) {
            return strcasecmp($a['name'] ?? $a['id'], $b['name'] ?? $b['id']);
        });
        foreach ($userLabels as $label) {
            $ids[] = $label['id'];
        }
        $result = [];
        foreach ($ids as $id) {
            $result[] = ['id' => $id];
        }
        return $result;
    }

    public function GetFolder($id) {
        if ($id === GMAIL_ALLMAIL_ID) {
            $folder = new SyncFolder();
            $folder->serverid = $id;
            $folder->parentid = "0";
            $folder->displayname = "All Mail";
            $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
            return $folder;
        }
        $labels = $this->loadLabels();
        if (!isset($labels[$id])) {
            throw new Exception("BackendGmail::GetFolder: unknown folder '$id'");
        }
        $label = $labels[$id];
        $folder = new SyncFolder();
        $folder->serverid = $id;
        $folder->parentid = "0";
        switch ($id) {
            case 'INBOX':
                $folder->displayname = "Inbox";
                $folder->type = SYNC_FOLDER_TYPE_INBOX;
                break;
            case 'SENT':
                $folder->displayname = "Sent";
                $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
                break;
            case 'DRAFT':
                $folder->displayname = "Drafts";
                $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
                break;
            case 'TRASH':
                $folder->displayname = "Trash";
                $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
                break;
            case 'SPAM':
                $folder->displayname = "Junk";
                $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
                break;
            default:
                $folder->displayname = $label['name'] ?? $id;
                $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
                break;
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->GetFolder('%s'): displayname='%s' type=%d", $id, $folder->displayname, $folder->type));
        return $folder;
    }

    public function StatFolder($id) {
        $folder = $this->GetFolder($id);
        $stat = [];
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;
        return $stat;
    }

    public function ChangeFolder($folderid, $oldid, $displayname, $type) {
        throw new Exception("BackendGmail::ChangeFolder not implemented yet (folder create/rename via device not supported in v1)");
    }

    public function DeleteFolder($id, $parentid) {
        throw new Exception("BackendGmail::DeleteFolder not implemented yet");
    }

    // Stat cache for GetMessageList: keyed by user + folder + cutoff, validated
    // by the Gmail profile historyId captured BEFORE the fetch. If historyId
    // is unchanged, the cached stat list (and metaCache for the preview path)
    // is returned without any messages.list / metadata calls. History change,
    // cutoff change, corrupt cache, or profile failure all fall back to a full
    // fetch (never serve stale data on an error). Per-folder file lock gives
    // single-flight: concurrent fetches for the same folder serialize, with a
    // 5s timeout after which the caller fetches anyway (never hangs the phone).
    protected function statCacheFile($folderid, $cutoffdate) {
        $key = sha1((string)($this->username ?? '') . '|' . (string)$folderid . '|' . (string)$cutoffdate);
        $dir = defined('GMAIL_STAT_CACHE_DIR') ? GMAIL_STAT_CACHE_DIR : '/var/lib/z-push';
        return rtrim($dir, '/') . '/.gmail-statcache-' . $key . '.json';
    }

    protected function acquireStatLock($lockFile) {
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) return false;
        $deadline = microtime(true) + 5.0;
        while (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) { fclose($fp); return false; }
            usleep(100000);
        }
        return $fp;
    }

    protected function releaseStatLock($fp) {
        if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
    }

    protected function readStatCache($file, $folderid, $cutoffdate, $historyId) {
        $content = @file_get_contents($file);
        if ($content === false) return null;
        $data = json_decode($content, true);
        if (!is_array($data)) return null;
        if (($data['user'] ?? null) !== (string)($this->username ?? '')) return null;
        if (($data['folderid'] ?? null) !== (string)$folderid) return null;
        if ((string)($data['cutoffdate'] ?? '') !== (string)$cutoffdate) return null;
        if ((string)($data['historyId'] ?? '') !== (string)$historyId) return null;
        if (!isset($data['messages']) || !is_array($data['messages'])) return null;
        if (!isset($data['meta']) || !is_array($data['meta'])) return null;
        return $data;
    }

    protected function writeStatCache($file, $folderid, $cutoffdate, $historyId, $messages, $meta) {
        $data = ['user' => (string)($this->username ?? ''), 'folderid' => (string)$folderid, 'cutoffdate' => (string)$cutoffdate, 'historyId' => (string)$historyId, 'messages' => $messages, 'meta' => $meta];
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data)) === false) return false;
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }

    protected function getCurrentHistoryId() {
        try {
            $profile = $this->ensureApi()->getProfile();
            return isset($profile['historyId']) ? (string)$profile['historyId'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function GetMessageList($folderid, $cutoffdate) {
        $file = $this->statCacheFile($folderid, $cutoffdate);
        $lock = $this->acquireStatLock($file . '.lock');
        try {
            $historyId = $this->getCurrentHistoryId();
            if ($historyId !== null) {
                $cached = $this->readStatCache($file, $folderid, $cutoffdate, $historyId);
                if ($cached !== null) {
                    $this->metaCache = $cached['meta'];
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->GetMessageList('%s'): stat cache hit (historyId %s)", $folderid, $historyId));
                    return $cached['messages'];
                }
            }
            $messages = $this->fetchFolderStatList($folderid, $cutoffdate);
            if ($historyId !== null) {
                $this->writeStatCache($file, $folderid, $cutoffdate, $historyId, $messages, $this->metaCache);
            }
            return $messages;
        } finally {
            $this->releaseStatLock($lock);
        }
    }

    protected function fetchFolderStatList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->GetMessageList('%s','%s')", $folderid, $cutoffdate));
        list($labelId, $isAllMail) = $this->resolveFolderLabel($folderid);
        $qParts = [];
        if ($isAllMail) {
            $qParts[] = "-in:trash -in:spam";
        }
        if ($cutoffdate > 0) {
            $qParts[] = "after:" . gmdate("Y/m/d", (int)$cutoffdate - 86400);
        }
        $q = count($qParts) > 0 ? implode(" ", $qParts) : null;
        $api = $this->ensureApi();
        $ids = [];
        $pageToken = null;
        do {
            $page = $api->listMessages($labelId, $pageToken, $q);
            foreach (($page['messages'] ?? []) as $m) {
                if (isset($m['id'])) {
                    $ids[] = $m['id'];
                }
            }
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken !== null && $pageToken !== '');
        $messages = [];
        $this->metaCache = [];
        $batch = null;
        try {
            $batch = $api->getMessagesMetadataBatch($ids);
        } catch (Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGmail->GetMessageList('%s'): metadata batch failed, falling back to sequential: %s", $folderid, $e->getMessage()));
            $batch = null;
        }
        foreach ($ids as $id) {
            if (is_array($batch) && array_key_exists($id, $batch)) {
                $meta = $batch[$id];
                if ($meta === null) {
                    continue;
                }
                $labelIds = $meta['labelIds'] ?? [];
                if (!is_array($labelIds)) {
                    $labelIds = [];
                }
                $internalDate = (string)($meta['internalDate'] ?? '0');
                $this->metaCache[$id] = ['labelIds' => $labelIds, 'internalDate' => $internalDate, 'snippet' => (string)($meta['snippet'] ?? ''), 'headers' => $meta['headers'] ?? []];
            } else {
                try {
                    list($labelIds, $internalDate) = $this->fetchMessageMetadata($id);
                } catch (GmailApiException $e) {
                    if ($e->httpStatus == 404) {
                        continue;
                    }
                    throw $e;
                }
                $this->metaCache[$id] = ['labelIds' => $labelIds, 'internalDate' => $internalDate, 'snippet' => '', 'headers' => []];
            }
            if ($cutoffdate > 0 && (int)$internalDate < ((int)$cutoffdate * 1000)) {
                continue;
            }
            if ($isAllMail && (in_array('TRASH', $labelIds, true) || in_array('SPAM', $labelIds, true))) {
                continue;
            }
            $messages[] = $this->computeStat($id, $labelIds, $internalDate);
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->GetMessageList('%s'): %d messages", $folderid, count($messages)));
        try {
            $profile = $api->getProfile();
            if (isset($profile['historyId'])) {
                $this->updateHighWater($profile['historyId']);
            }
        } catch (Throwable $e) {
        }
        return $messages;
    }

    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->GetMessage('%s','%s')", $folderid, $id));
        // Snippet fast path for list Sync: GetMessageList in this same request
        // already fetched metadata (labelIds/internalDate/snippet/headers) in
        // parallel. If the client explicitly asked for a preview (list view),
        // return the cached snippet instead of fetching full MIME for every
        // mail in the list. Detail fetches (no preview, or no cache) still
        // fetch full MIME below. Default ContentParameters (existing tests)
        // never triggers this path.
        try {
            $previewSize = $this->requestedPreviewSize($contentparameters);
        } catch (Throwable $e) {
            $previewSize = 0;
        }
        if ($previewSize > 0 && isset($this->metaCache[$id]) && !empty($this->metaCache[$id]['headers'])) {
            $cached = $this->metaCache[$id];
            $headers = [];
            foreach ($cached['headers'] as $h) {
                $n = strtolower(trim((string)($h['name'] ?? '')));
                $v = trim((string)($h['value'] ?? ''));
                if ($n === '' || isset($headers[$n])) {
                    continue;
                }
                $headers[$n] = $this->decodeMimeHeader($v);
            }
            $snippet = (string)($cached['snippet'] ?? '');
            $stat = $this->computeStat($id, $cached['labelIds'] ?? [], (string)($cached['internalDate'] ?? '0'));
            $output = new SyncMail();
            $output->asbody = new SyncBaseBody();
            $output->asbody->type = SYNC_BODYPREFERENCE_PLAIN;
            $output->asbody->data = StringStreamWrapper::Open($snippet, false);
            $output->asbody->estimatedDataSize = strlen($snippet);
            $output->asbody->truncated = 1;
            if (Request::GetProtocolVersion() >= 14.0) {
                $pv = mb_substr($snippet, 0, $previewSize, 'UTF-8');
                $output->asbody->preview = ($pv === false) ? $snippet : $pv;
            }
            $output->nativebodytype = SYNC_BODYPREFERENCE_PLAIN;
            $output->datereceived = isset($headers['date']) ? $this->cleanupDateValue($headers['date']) : null;
            $output->messageclass = "IPM.Note";
            $output->subject = $headers['subject'] ?? "";
            $output->read = $stat['flags'];
            $output->from = $headers['from'] ?? null;
            $output->threadtopic = $headers['thread-topic'] ?? $output->subject;
            $output->internetcpid = INTERNET_CPID_UTF8;
            if (isset($headers['importance'])) {
                $imp = strtolower($headers['importance']);
                if ($imp == "normal") $output->importance = 1;
                elseif ($imp == "high") $output->importance = 2;
                elseif ($imp == "low") $output->importance = 0;
                else $output->importance = 1;
            } elseif (isset($headers['x-priority'])) {
                $prio = preg_replace("/\D+/", "", $headers['x-priority']);
                if ($prio == 3) $output->importance = 1;
                elseif ($prio !== '' && $prio < 3) $output->importance = 2;
                elseif ($prio !== '' && $prio > 3) $output->importance = 0;
                else $output->importance = 1;
            } else {
                $output->importance = 1;
            }
            $output->to = [];
            $output->cc = [];
            $output->reply_to = [];
            $displayTo = null;
            foreach (['to' => 'to', 'cc' => 'cc', 'reply-to' => 'reply_to'] as $headerName => $field) {
                if (!isset($headers[$headerName])) continue;
                list($addrs, $firstName) = $this->formatAddressHeader($headers[$headerName]);
                foreach ($addrs as $a) {
                    $output->{$field}[] = $a;
                }
                if ($displayTo === null && $firstName !== null && $firstName !== '') {
                    $displayTo = $firstName;
                }
            }
            if ($displayTo !== null) {
                $output->displayto = $displayTo;
            }
            if (Request::GetProtocolVersion() >= 12.0) {
                $output->contentclass = DEFAULT_EMAIL_CONTENTCLASS;
                $output->flag = new SyncMailFlags();
                if (isset($stat['star']) && $stat['star']) {
                    $output->flag->flagstatus = SYNC_FLAGSTATUS_ACTIVE;
                    $output->flag->flagtype = "FollowUp";
                } else {
                    $output->flag->flagstatus = SYNC_FLAGSTATUS_CLEAR;
                }
                if (Request::GetProtocolVersion() >= 14.0) {
                    if (isset($stat['answered']) && $stat['answered']) {
                        $output->lastverbexecuted = SYNC_MAIL_LASTVERB_REPLYSENDER;
                    } elseif (isset($stat['forwarded']) && $stat['forwarded']) {
                        $output->lastverbexecuted = SYNC_MAIL_LASTVERB_FORWARD;
                    } else {
                        $output->lastverbexecuted = SYNC_MAIL_LASTVERB_UNKNOWN;
                    }
                }
            }
            return $output;
        }
        $api = $this->ensureApi();
        $result = $api->getMessage($id, 'raw');
        $rawB64 = $result['raw'] ?? '';
        if ($rawB64 === '') {
            throw new StatusException("BackendGmail->GetMessage(): Error, message not found or empty raw", SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);
        }
        $rawMime = $this->base64urlDecode($rawB64);
        if ($rawMime === false) {
            throw new StatusException("BackendGmail->GetMessage(): Error, raw MIME base64url decode failed", SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);
        }
        $stat = $this->computeStat($id, $result['labelIds'] ?? [], (string)($result['internalDate'] ?? '0'));
        $headers = $this->parseMimeHeaders($rawMime);
        $output = new SyncMail();
        $output->asbody = new SyncBaseBody();
        $output->asbody->type = SYNC_BODYPREFERENCE_MIME;
        $output->asbody->data = StringStreamWrapper::Open($rawMime, false);
        $output->asbody->estimatedDataSize = strlen($rawMime);
        $output->asbody->truncated = 0;
        $output->nativebodytype = SYNC_BODYPREFERENCE_PLAIN;
        $output->datereceived = isset($headers['date']) ? $this->cleanupDateValue($headers['date']) : null;
        $output->messageclass = "IPM.Note";
        $output->subject = $headers['subject'] ?? "";
        $output->read = $stat['flags'];
        $output->from = $headers['from'] ?? null;
        $output->threadtopic = $headers['thread-topic'] ?? $output->subject;
        $output->internetcpid = INTERNET_CPID_UTF8;
        if (isset($headers['importance'])) {
            $imp = strtolower($headers['importance']);
            if ($imp == "normal") $output->importance = 1;
            elseif ($imp == "high") $output->importance = 2;
            elseif ($imp == "low") $output->importance = 0;
            else $output->importance = 1;
        } elseif (isset($headers['x-priority'])) {
            $prio = preg_replace("/\D+/", "", $headers['x-priority']);
            if ($prio == 3) $output->importance = 1;
            elseif ($prio !== '' && $prio < 3) $output->importance = 2;
            elseif ($prio !== '' && $prio > 3) $output->importance = 0;
            else $output->importance = 1;
        } else {
            $output->importance = 1;
        }
        $output->to = [];
        $output->cc = [];
        $output->reply_to = [];
        $displayTo = null;
        foreach (['to' => 'to', 'cc' => 'cc', 'reply-to' => 'reply_to'] as $headerName => $field) {
            if (!isset($headers[$headerName])) continue;
            list($addrs, $firstName) = $this->formatAddressHeader($headers[$headerName]);
            foreach ($addrs as $a) {
                $output->{$field}[] = $a;
            }
            if ($displayTo === null && $firstName !== null && $firstName !== '') {
                $displayTo = $firstName;
            }
        }
        if ($displayTo !== null) {
            $output->displayto = $displayTo;
        }
        if (Request::GetProtocolVersion() >= 12.0) {
            $output->contentclass = DEFAULT_EMAIL_CONTENTCLASS;
            $output->flag = new SyncMailFlags();
            if (isset($stat['star']) && $stat['star']) {
                $output->flag->flagstatus = SYNC_FLAGSTATUS_ACTIVE;
                $output->flag->flagtype = "FollowUp";
            } else {
                $output->flag->flagstatus = SYNC_FLAGSTATUS_CLEAR;
            }
            if (Request::GetProtocolVersion() >= 14.0) {
                if (isset($stat['answered']) && $stat['answered']) {
                    $output->lastverbexecuted = SYNC_MAIL_LASTVERB_REPLYSENDER;
                } elseif (isset($stat['forwarded']) && $stat['forwarded']) {
                    $output->lastverbexecuted = SYNC_MAIL_LASTVERB_FORWARD;
                } else {
                    $output->lastverbexecuted = SYNC_MAIL_LASTVERB_UNKNOWN;
                }
            }
        }
        return $output;
    }

    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendGmail->StatMessage('%s','%s')", $folderid, $id));
        try {
            list($labelIds, $internalDate) = $this->fetchMessageMetadata($id);
        } catch (GmailApiException $e) {
            if ($e->httpStatus == 404 || ($e->httpStatus == 400 && strpos($e->getMessage(), 'startHistoryId') !== false)) {
                return false;
            }
            throw $e;
        }
        return $this->computeStat($id, $labelIds, $internalDate);
    }

    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        throw new Exception("BackendGmail::ChangeMessage not implemented yet");
    }

    public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        $api = $this->ensureApi();
        try {
            if ($flags == 0) {
                $api->modifyMessage($id, ['UNREAD'], []);
            } else {
                $api->modifyMessage($id, [], ['UNREAD']);
            }
        } catch (GmailApiException $e) {
            if ($e->httpStatus == 404) {
                return true;
            }
            throw $e;
        }
        return true;
    }

    public function DeleteMessage($folderid, $id, $contentParameters) {
        $api = $this->ensureApi();
        try {
            list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($id);
        } catch (GmailApiException $e) {
            if ($e->httpStatus == 404) {
                return true;
            }
            throw $e;
        }
        if ($folderid === 'TRASH') {
            try {
                $api->deleteMessage($id);
            } catch (GmailApiException $e) {
                if ($e->httpStatus == 404) {
                    return true;
                }
                throw $e;
            }
            return true;
        }
        if ($folderid === 'INBOX' || $folderid === GMAIL_ALLMAIL_ID) {
            $api->trashMessage($id);
            return true;
        }
        $labels = $this->loadLabels();
        if (isset($labels[$folderid])) {
            $api->modifyMessage($id, ['TRASH'], [$folderid]);
            return true;
        }
        $api->trashMessage($id);
        return true;
    }

    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        if ($folderid === $newfolderid) {
            throw new StatusException(sprintf("BackendGmail->MoveMessage('%s','%s','%s'): destination is source folder", $folderid, $id, $newfolderid), SYNC_MOVEITEMSSTATUS_SAMESOURCEANDDEST);
        }
        $api = $this->ensureApi();
        $labels = $this->loadLabels();
        if ($folderid === GMAIL_ALLMAIL_ID) {
            $srcLabel = null; $srcIsAllMail = true;
        } elseif (isset($labels[$folderid])) {
            $srcLabel = $folderid; $srcIsAllMail = false;
        } else {
            throw new StatusException(sprintf("BackendGmail->MoveMessage('%s','%s','%s'): invalid source folder", $folderid, $id, $newfolderid), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
        }
        if ($newfolderid === GMAIL_ALLMAIL_ID) {
            $dstLabel = null; $dstIsAllMail = true;
        } elseif (isset($labels[$newfolderid])) {
            $dstLabel = $newfolderid; $dstIsAllMail = false;
        } else {
            throw new StatusException(sprintf("BackendGmail->MoveMessage('%s','%s','%s'): invalid destination folder", $folderid, $id, $newfolderid), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);
        }
        try {
            list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($id);
        } catch (GmailApiException $e) {
            if ($e->httpStatus == 404) {
                throw new StatusException(sprintf("BackendGmail->MoveMessage('%s','%s','%s'): source message not found", $folderid, $id, $newfolderid), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
            }
            throw $e;
        }
        $add = []; $remove = [];
        if ($dstLabel === 'TRASH') {
            $add[] = 'TRASH';
            if (!$srcIsAllMail) { $remove[] = $srcLabel; }
        } elseif ($dstIsAllMail) {
            if (!$srcIsAllMail) { $remove[] = $srcLabel; }
        } else {
            $add[] = $dstLabel;
            if (!$srcIsAllMail) { $remove[] = $srcLabel; }
        }
        try {
            $api->modifyMessage($id, $add, $remove);
        } catch (GmailApiException $e) {
            throw new StatusException(sprintf("BackendGmail->MoveMessage('%s','%s','%s'): Gmail API error", $folderid, $id, $newfolderid), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);
        }
        return $id . "";
    }

    public function SendMail($sm) {
        if (!isset($sm->mime) || $sm->mime === '' || $sm->mime === null) {
            throw new StatusException("BackendGmail->SendMail(): no MIME to send", SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }
        $api = $this->ensureApi();
        $hasSource = isset($sm->source->itemid) && $sm->source->itemid !== '' && $sm->source->itemid !== null;
        $replaceMime = isset($sm->replacemime) && $sm->replacemime !== false;
        $isReply = !empty($sm->replyflag);
        $isForward = !$isReply && !empty($sm->forwardflag);
        $isSmart = $hasSource && !$replaceMime && ($isReply || $isForward);
        if (!$isSmart) {
            try {
                $api->sendMessage($sm->mime);
            } catch (GmailApiException $e) {
                throw new StatusException(sprintf("BackendGmail->SendMail(): Gmail API error %d", $e->httpStatus), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
            }
            return true;
        }
        $newRawHeaders = $this->parseRawMimeHeaders($sm->mime);
        $topContentType = $this->getRawHeaderValue($newRawHeaders, 'content-type');
        $isSmime = $topContentType !== null && (stripos($topContentType, 'multipart/signed') !== false || stripos($topContentType, 'application/pkcs7') !== false || stripos($topContentType, 'application/x-pkcs7') !== false || stripos($topContentType, 'multipart/encrypted') !== false);
        $hasCalendar = stripos($sm->mime, 'text/calendar') !== false || stripos($sm->mime, 'BEGIN:VCALENDAR') !== false;
        if ($isSmime || $hasCalendar) {
            ZLog::Write(LOGLEVEL_WARN, "BackendGmail->SendMail(): Smart merging skipped for S/MIME/calendar new message; sending provided MIME as-is");
            try {
                $api->sendMessage($sm->mime);
            } catch (GmailApiException $e) {
                throw new StatusException(sprintf("BackendGmail->SendMail(): Gmail API error %d", $e->httpStatus), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
            }
            return true;
        }
        try {
            $srcResp = $api->getMessage($sm->source->itemid, 'raw');
            $srcRawB64 = $srcResp['raw'] ?? '';
            if ($srcRawB64 === '') {
                throw new Exception("empty source raw");
            }
            $sourceRaw = $this->base64urlDecode($srcRawB64);
            if ($sourceRaw === false || $sourceRaw === '') {
                throw new Exception("source raw decode failed");
            }
        } catch (Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, "BackendGmail->SendMail(): Smart source fetch failed, sending provided MIME as-is");
            try {
                $api->sendMessage($sm->mime);
            } catch (GmailApiException $ge) {
                throw new StatusException(sprintf("BackendGmail->SendMail(): Gmail API error %d", $ge->httpStatus), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
            }
            return true;
        }
        try {
            $finalMime = $this->buildSmartMime($sm, $sourceRaw, $isReply);
        } catch (Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, "BackendGmail->SendMail(): Smart merging failed, sending provided MIME as-is");
            try {
                $api->sendMessage($sm->mime);
            } catch (GmailApiException $ge) {
                throw new StatusException(sprintf("BackendGmail->SendMail(): Gmail API error %d", $ge->httpStatus), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
            }
            return true;
        }
        try {
            $api->sendMessage($finalMime);
        } catch (GmailApiException $e) {
            throw new StatusException(sprintf("BackendGmail->SendMail(): Gmail API error %d", $e->httpStatus), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }
        return true;
    }

    private function parseRawMimeHeaders($rawMime) {
        $posCRLF = strpos($rawMime, "\r\n\r\n");
        $posLF = strpos($rawMime, "\n\n");
        $pos = false;
        if ($posCRLF !== false && ($posLF === false || $posCRLF <= $posLF)) {
            $pos = $posCRLF;
        } elseif ($posLF !== false) {
            $pos = $posLF;
        }
        $headersBlock = ($pos === false) ? $rawMime : substr($rawMime, 0, $pos);
        $headersBlock = preg_replace("/\r\n[ \t]+/", " ", $headersBlock);
        $headersBlock = preg_replace("/\n[ \t]+/", " ", $headersBlock);
        $lines = preg_split("/\r\n|\n/", $headersBlock);
        $out = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if ($name === '') {
                continue;
            }
            $exists = false;
            foreach ($out as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function getRawHeaderValue($headers, $lowerName) {
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $lowerName) {
                return $v;
            }
        }
        return null;
    }

    private function addTextPartsGmail(&$email, &$message, &$sourceMessage, $isReply = true) {
        $htmlBody = $plainBody = '';
        Mail_mimeDecode::getBodyRecursive($message, "html", $htmlBody);
        Mail_mimeDecode::getBodyRecursive($message, "plain", $plainBody);
        $htmlSource = $plainSource = '';
        Mail_mimeDecode::getBodyRecursive($sourceMessage, "html", $htmlSource);
        Mail_mimeDecode::getBodyRecursive($sourceMessage, "plain", $plainSource);
        $separator = '';
        if ($isReply) {
            $separator = ">\r\n";
            $separatorHtml = "<blockquote>";
            $separatorHtmlEnd = "</blockquote></body></html>";
        } else {
            $separator = "";
            $separatorHtml = "<div>";
            $separatorHtmlEnd = "</div>";
        }
        $altEmail = new Mail_mimePart('', array('content_type' => 'multipart/alternative'));
        if (strlen($htmlBody) > 0 || strlen($htmlSource) > 0 || strlen($plainSource) > 0) {
            if (strlen($htmlSource) > 0) {
                $altEmail->addSubPart($htmlBody . $separatorHtml . $htmlSource . $separatorHtmlEnd, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
            } else {
                $altEmail->addSubPart($htmlBody . $separatorHtml . "<p>" . $plainSource . "</p>" . $separatorHtmlEnd, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
            }
        }
        if (strlen($plainBody) > 0 || strlen($plainSource) > 0 || strlen($htmlSource) > 0) {
            if (strlen($plainSource) > 0) {
                $altEmail->addSubPart($plainBody . $separator . str_replace("\n", "\n> ", "> " . $plainSource), array('content_type' => 'text/plain; charset=utf-8', 'encoding' => 'base64'));
            } else {
                $altEmail->addSubPart($plainBody . $separator . str_replace("\n", "\n> ", "> " . Utils::ConvertHtmlToText($htmlSource)), array('content_type' => 'text/plain; charset=utf-8', 'encoding' => 'base64'));
            }
        }
        $boundary = '=_' . md5(rand() . microtime());
        $altEmail = $altEmail->encode($boundary);
        $email->addSubPart($altEmail['body'], array('content_type' => 'multipart/alternative;'."\n".' boundary="'.$boundary.'"'));
        unset($altEmail);
        unset($htmlBody);
        unset($htmlSource);
        unset($plainBody);
        unset($plainSource);
    }

    private function addTextPartsMessageGmail(&$email, &$message) {
        $altEmail = new Mail_mimePart('', array('content_type' => 'multipart/alternative'));
        foreach (array("plain", "html", "calendar") as $type) {
            $body = '';
            Mail_mimeDecode::getBodyRecursive($message, $type, $body);
            if (strlen($body) > 0) {
                $altEmail->addSubPart($body, array('content_type' => sprintf("text/%s; charset=utf-8", $type), 'encoding' => 'base64'));
            }
        }
        unset($body);
        $boundary = '=_' . md5(rand() . microtime());
        $altEmail = $altEmail->encode($boundary);
        $email->addSubPart($altEmail['body'], array('content_type' => 'multipart/alternative;'."\n".' boundary="'.$boundary.'"'));
        unset($altEmail);
    }

    private function addExtraSubPartsGmail(&$email, $parts) {
        if (isset($parts)) {
            foreach ($parts as $part) {
                $new_part = null;
                if (isset($part->disposition) && $part->disposition == "attachment") {
                    $new_part = $this->addSubPartGmail($email, $part);
                } else {
                    if (isset($part->ctype_primary) && $part->ctype_primary != "text" && $part->ctype_primary != "multipart") {
                        $new_part = $this->addSubPartGmail($email, $part);
                    }
                }
                if (isset($part->parts)) {
                    if ($new_part === null) {
                        $this->addExtraSubPartsGmail($email, $part->parts);
                    } else {
                        $this->addExtraSubPartsGmail($new_part, $part->parts);
                    }
                }
            }
        }
    }

    private function addSubPartGmail(&$email, $part) {
        $new_part = null;
        $params = array();
        $params['content_type'] = '';
        if (isset($part) && isset($email)) {
            if (isset($part->ctype_primary)) {
                $params['content_type'] = $part->ctype_primary;
                if (isset($part->ctype_secondary)) {
                    $params['content_type'] .= '/' . $part->ctype_secondary;
                }
            }
            if (isset($part->ctype_parameters)) {
                foreach ($part->ctype_parameters as $k => $v) {
                    if (strcasecmp($k, 'boundary') != 0) {
                        $params['content_type'] .= '; ' . $k . '=' . $v;
                    }
                }
            }
            if (isset($part->disposition)) {
                $params['disposition'] = $part->disposition;
            }
            if (isset($part->d_parameters)) {
                $params['headers_charset'] = 'utf-8';
                foreach ($part->d_parameters as $k => $v) {
                    $params[$k] = $v;
                }
            }
            foreach ($part->headers as $k => $v) {
                switch ($k) {
                    case "content-description":
                        $params['description'] = $v;
                        break;
                    case "content-type":
                    case "content-disposition":
                    case "content-transfer-encoding":
                        break;
                    case "content-id":
                        $params['cid'] = str_replace('<', '', str_replace('>', '', $v));
                        break;
                    default:
                        $params[$k] = $v;
                        break;
                }
            }
            if (!isset($params['encoding']) && isset($part->body)) {
                $params['encoding'] = 'base64';
            }
            $new_part = $email->addSubPart(isset($part->body) ? $part->body : "", $params);
            unset($params);
        }
        return $new_part;
    }

    private function buildSmartMime($sm, $sourceRaw, $isReply) {
        $mobj = new Mail_mimeDecode($sm->mime);
        $message = $mobj->decode(array('decode_headers' => 'utf-8', 'decode_bodies' => true, 'include_bodies' => true, 'rfc_822bodies' => true, 'charset' => 'utf-8'));
        unset($mobj);
        $mobjSrc = new Mail_mimeDecode($sourceRaw);
        $sourceMessage = $mobjSrc->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'rfc_822bodies' => true, 'charset' => 'utf-8'));
        unset($mobjSrc);
        $newRawHeaders = $this->parseRawMimeHeaders($sm->mime);
        $srcRawHeaders = $this->parseRawMimeHeaders($sourceRaw);
        $finalEmail = new Mail_mimePart('', array('content_type' => 'multipart/mixed'));
        if ($isReply) {
            $this->addTextPartsGmail($finalEmail, $message, $sourceMessage, true);
            if (isset($message->parts)) {
                $this->addExtraSubPartsGmail($finalEmail, $message->parts);
            }
        } else {
            $this->addTextPartsMessageGmail($finalEmail, $message);
            if (isset($message->parts)) {
                $this->addExtraSubPartsGmail($finalEmail, $message->parts);
            }
            $finalEmail->addSubPart($sourceRaw, array('content_type' => 'message/rfc822', 'encoding' => 'base64', 'disposition' => 'attachment', 'dfilename' => 'forwarded_message.eml'));
        }
        $boundary = '=_' . md5(rand() . microtime());
        $encoded = $finalEmail->encode($boundary);
        $finalBody = "This is a multi-part message in MIME format.\n" . $encoded['body'];
        $finalHeaders = array('MIME-Version' => '1.0');
        foreach ($newRawHeaders as $k => $v) {
            if (strcasecmp($k, 'content-type') != 0 && strcasecmp($k, 'content-transfer-encoding') != 0 && strcasecmp($k, 'mime-version') != 0) {
                $dup = false;
                foreach ($finalHeaders as $fk => $fv) {
                    if (strcasecmp($fk, $k) === 0) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $finalHeaders[$k] = $v;
                }
            }
        }
        foreach ($encoded['headers'] as $k => $v) {
            $dup = false;
            foreach ($finalHeaders as $fk => $fv) {
                if (strcasecmp($fk, $k) === 0) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $finalHeaders[$k] = $v;
            }
        }
        $hasFrom = false;
        foreach ($finalHeaders as $k => $v) {
            if (strcasecmp($k, 'from') === 0) {
                $hasFrom = true;
                break;
            }
        }
        if (!$hasFrom) {
            if (isset($this->username) && $this->username !== '') {
                $finalHeaders['From'] = $this->username;
            } else {
                throw new Exception("BackendGmail->buildSmartMime(): no From header and no Logon username");
            }
        }
        foreach ($finalHeaders as $k => $v) {
            if (strcasecmp($k, 'subject') === 0 && preg_match('/[^\x00-\x7F]/', $v) === 1) {
                $finalHeaders[$k] = mb_encode_mimeheader($v, 'UTF-8', 'B', "\r\n");
                break;
            }
        }
        if ($isReply) {
            $srcMsgId = $this->getRawHeaderValue($srcRawHeaders, 'message-id');
            if ($srcMsgId !== null && $srcMsgId !== '') {
                foreach (array_keys($finalHeaders) as $k) {
                    if (strcasecmp($k, 'in-reply-to') === 0 || strcasecmp($k, 'references') === 0) {
                        unset($finalHeaders[$k]);
                    }
                }
                $finalHeaders['In-Reply-To'] = $srcMsgId;
                $srcRefs = $this->getRawHeaderValue($srcRawHeaders, 'references');
                if ($srcRefs !== null && $srcRefs !== '') {
                    $finalHeaders['References'] = $srcRefs . ' ' . $srcMsgId;
                } else {
                    $finalHeaders['References'] = $srcMsgId;
                }
            } else {
                ZLog::Write(LOGLEVEL_WARN, "BackendGmail->buildSmartMime(): source has no Message-ID, threading headers skipped (quoted body still included)");
            }
        }
        $finalMime = "";
        foreach ($finalHeaders as $k => $v) {
            $finalMime .= $k . ": " . $v . "\r\n";
        }
        $finalMime .= "\r\n" . $finalBody;
        return $finalMime;
    }

    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }

    public function GetWasteBasket() {
        return 'TRASH';
    }

    public function HasChangesSink() {
        return true;
    }

    public function ChangesSinkInitialize($folderid) {
        try {
            $this->resolveFolderLabel($folderid);
        } catch (Throwable $e) {
            return false;
        }
        if (!in_array($folderid, $this->watchedFolders, true)) {
            $this->watchedFolders[] = $folderid;
        }
        return true;
    }

    private function checkWakeFlag() {
        $file = defined('GMAIL_WAKE_FLAG_FILE') ? GMAIL_WAKE_FLAG_FILE : '/var/lib/z-push/.gmail-wake.json';
        try {
            if (!is_file($file)) return null;
            $data = json_decode(@file_get_contents($file), true);
            if (!is_array($data) || !isset($data['historyId']) || (string)$data['historyId'] === '') return null;
            return (string)$data['historyId'];
        } catch (Throwable $e) {
            return null;
        }
    }

    public function ChangesSink($timeout = 30) {
        if (empty($this->watchedFolders)) {
            return [];
        }
        $api = $this->ensureApi();
        $poll = defined('GMAIL_POLL_INTERVAL') ? (int)GMAIL_POLL_INTERVAL : 10;
        if ($poll <= 0) {
            $poll = 10;
        }
        $wakeCheck = defined('GMAIL_WAKE_CHECK_INTERVAL') ? (int)GMAIL_WAKE_CHECK_INTERVAL : 2;
        if ($wakeCheck <= 0) {
            $wakeCheck = 2;
        }
        $end = microtime(true) + (int)$timeout;
        $lastPoll = 0;
        while (true) {
            // Pub/Sub wake first: cheap file read, no API cost, no history consumption.
            // The following Sync's GetChanges (per-folder lastHistoryId) is still authoritative.
            $wake = $this->checkWakeFlag();
            if ($wake !== null) {
                $hw = $this->getHighWater();
                if ($hw === null || (int)$wake > (int)$hw) {
                    $this->updateHighWater($wake);
                    return $this->watchedFolders;
                }
            }
            $now = microtime(true);
            if ($now - $lastPoll >= $poll) {
                $lastPoll = $now;
                $cur = null;
                try {
                    $profile = $api->getProfile();
                    if (isset($profile['historyId'])) {
                        $cur = $profile['historyId'];
                    }
                } catch (GmailApiException $e) {
                    ZLog::Write(LOGLEVEL_WARN, sprintf("BackendGmail ChangesSink profile check failed (HTTP %d)", $e->httpStatus));
                    $cur = null;
                } catch (Throwable $e) {
                    ZLog::Write(LOGLEVEL_WARN, "BackendGmail ChangesSink profile check failed");
                    $cur = null;
                }
                if ($cur !== null) {
                    $hw = $this->getHighWater();
                    if ($hw === null || (int)$cur > (int)$hw) {
                        $this->updateHighWater($cur);
                        return $this->watchedFolders;
                    }
                }
                $now = microtime(true);
            }
            if ($now >= $end) {
                break;
            }
            $untilPoll = $poll - ($now - $lastPoll);
            if ($untilPoll <= 0) $untilPoll = $poll;
            $sleep = min($wakeCheck, $untilPoll, $end - $now);
            if ($sleep > 0) {
                usleep((int)($sleep * 1000000));
            } else {
                break;
            }
        }
        return [];
    }

    private function requestedPreviewSize($contentparameters) {
        if (!is_object($contentparameters) || !method_exists($contentparameters, 'GetBodyPreference')) {
            return 0;
        }
        try {
            $types = $contentparameters->GetBodyPreference();
        } catch (Throwable $e) {
            return 0;
        }
        if ($types === false || !is_array($types)) {
            return 0;
        }
        foreach ($types as $type) {
            try {
                $bpo = $contentparameters->BodyPreference($type);
                if (!is_object($bpo)) {
                    continue;
                }
                // NOTE: GetPreview is a StateObject magic getter, not a real
                // method, so method_exists() would wrongly return false here.
                $pv = $bpo->GetPreview();
            } catch (Throwable $e) {
                continue;
            }
            if (is_numeric($pv) && (int)$pv > 0) {
                return (int)$pv;
            }
        }
        return 0;
    }

    public function EmptyFolder($folderid, $includeSubfolders = true) {
        // Permanent delete is only ever allowed for Trash / Junk. Any other
        // folder (Inbox, Sent, Drafts, All Mail, user labels) is rejected
        // with a StatusException so the client reports an honest failure
        // instead of a fake success that would make mail reappear on resync.
        if ($folderid !== 'TRASH' && $folderid !== 'SPAM') {
            throw new StatusException(sprintf("BackendGmail->EmptyFolder('%s'): permanent delete only allowed for Trash/Junk", $folderid), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);
        }
        $api = $this->ensureApi();
        $ids = [];
        $pageToken = null;
        do {
            $page = $api->listMessages($folderid, $pageToken, null);
            foreach (($page['messages'] ?? []) as $m) {
                if (isset($m['id'])) {
                    $ids[] = $m['id'];
                }
            }
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken !== null && $pageToken !== '');
        foreach ($ids as $id) {
            try {
                $api->deleteMessage($id);
            } catch (GmailApiException $e) {
                if ($e->httpStatus == 404) {
                    continue;
                }
                throw $e;
            }
        }
        return true;
    }

    public function GetAttachmentData($attname) {
        throw new StatusException("BackendGmail::GetAttachmentData not implemented yet (separate FetchAttachment deferred; attachments are delivered inline in MIME body)", SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);
    }

    private function resolveFolderLabel($folderid) {
        if ($folderid === GMAIL_ALLMAIL_ID) {
            return [null, true];
        }
        $labels = $this->loadLabels();
        if (isset($labels[$folderid])) {
            return [$folderid, false];
        }
        throw new StatusException("BackendGmail: folder not found", SYNC_STATUS_FOLDERHIERARCHYCHANGED);
    }

    private function computeStat($id, $labelIds, $internalDate) {
        $sorted = is_array($labelIds) ? $labelIds : [];
        sort($sorted, SORT_STRING);
        $mod = sha1(implode(',', $sorted) . '|' . (string)$internalDate);
        $flags = in_array('UNREAD', $labelIds, true) ? 0 : 1;
        $star = in_array('STARRED', $labelIds, true) ? 1 : 0;
        return ['id' => $id, 'mod' => $mod, 'flags' => $flags, 'answered' => 0, 'forwarded' => 0, 'star' => $star];
    }

    private function fetchMessageMetadata($id) {
        $result = $this->ensureApi()->getMessage($id, 'metadata');
        $labelIds = $result['labelIds'] ?? [];
        if (!is_array($labelIds)) {
            $labelIds = [];
        }
        $internalDate = (string)($result['internalDate'] ?? '0');
        return [$labelIds, $internalDate];
    }

    private function base64urlDecode($data) {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($b64, true);
    }

    private function parseMimeHeaders($rawMime) {
        $posCRLF = strpos($rawMime, "\r\n\r\n");
        $posLF = strpos($rawMime, "\n\n");
        $pos = false;
        $len = 0;
        if ($posCRLF !== false && ($posLF === false || $posCRLF <= $posLF)) {
            $pos = $posCRLF;
            $len = 4;
        } elseif ($posLF !== false) {
            $pos = $posLF;
            $len = 2;
        }
        $headersBlock = ($pos === false) ? $rawMime : substr($rawMime, 0, $pos);
        $headersBlock = preg_replace("/\r\n[ \t]+/", " ", $headersBlock);
        $headersBlock = preg_replace("/\n[ \t]+/", " ", $headersBlock);
        $lines = preg_split("/\r\n|\n/", $headersBlock);
        $out = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $out[$name] = $this->decodeMimeHeader($value);
        }
        return $out;
    }

    private function decodeMimeHeader($value) {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, 1, 'UTF-8');
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $value;
    }

    private function cleanupDateValue($receiveddate) {
        if (is_array($receiveddate)) {
            $receiveddate = $receiveddate[0];
        }
        $receivedtime = strtotime(preg_replace('/\(.*\)/', "", $receiveddate));
        if ($receivedtime === false || $receivedtime == -1) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("cleanupDateValue('%s'): strtotime() failed - message might be broken.", $receiveddate));
            return null;
        }
        return $receivedtime;
    }

    private function addAddressToList(&$addresses, $addr) {
        $name = "";
        if (isset($addr->mailbox) && isset($addr->host) && isset($addr->personal)) {
            $address = sprintf("%s@%s", $addr->mailbox, $addr->host);
            $name = $addr->personal;
            if (strlen($name) == 0 || $name == $address) {
                $fulladdr = $address;
            } else {
                if (preg_match('/^\\".*\\"$/', $name)) {
                    $fulladdr = sprintf("%s <%s>", $name, $address);
                } else {
                    $fulladdr = sprintf("\\\"%s\\\" <%s>", $name, $address);
                }
            }
            array_push($addresses, $fulladdr);
        }
        return $name;
    }

    private function formatAddressHeader($headerValue) {
        $addresses = [];
        $firstName = null;
        $mailRFC822 = new Mail_RFC822();
        $addrList = $mailRFC822->parseAddressList($headerValue, null, null, false, null);
        if ($addrList === false) {
            return [[$headerValue], null];
        }
        foreach ($addrList as $addr) {
            if (isset($addr->addresses)) {
                if (count($addr->addresses) == 0) {
                    $addresses[] = sprintf("%s:;", $addr->groupname);
                    if ($firstName === null && strlen($addr->groupname) > 0) {
                        $firstName = $addr->groupname;
                    }
                } else {
                    foreach ($addr->addresses as $subAddr) {
                        $name = $this->addAddressToList($addresses, $subAddr);
                        if ($firstName === null && strlen($name) > 0) {
                            $firstName = $name;
                        }
                    }
                }
            } else {
                $name = $this->addAddressToList($addresses, $addr);
                if ($firstName === null && strlen($name) > 0) {
                    $firstName = $name;
                }
            }
        }
        return [$addresses, $firstName];
    }

    public function GetChanges($folderid, &$state, $cutoffdate = 0) {
        $api = $this->ensureApi();
        list($folderLabelId, $isAllMail) = $this->resolveFolderLabel($folderid);
        if (!is_array($state)) {
            $state = [];
        }
        $lastHistoryId = $state['lastHistoryId'] ?? null;
        if ($lastHistoryId === null || $lastHistoryId === '') {
            $profile = $api->getProfile();
            $newHistoryId = $profile['historyId'] ?? null;
            $state['lastHistoryId'] = $newHistoryId;
            if ($newHistoryId !== null) {
                $this->updateHighWater($newHistoryId);
            }
            return [];
        }
        $historyTypes = ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'];
        $records = [];
        $responseHistoryId = null;
        $pageToken = null;
        try {
            do {
                $page = $api->listHistory($lastHistoryId, $pageToken, $historyTypes);
                foreach (($page['history'] ?? []) as $rec) {
                    $records[] = $rec;
                }
                if (isset($page['historyId'])) {
                    $responseHistoryId = $page['historyId'];
                }
                $pageToken = $page['nextPageToken'] ?? null;
            } while ($pageToken !== null && $pageToken !== '');
        } catch (GmailApiException $e) {
            if ($e->httpStatus == 404) {
                $profile = $api->getProfile();
                $newHistoryId = $profile['historyId'] ?? null;
                $fullList = $this->GetMessageList($folderid, $cutoffdate);
                $changes = [];
                foreach ($fullList as $m) {
                    $changes[] = ['type' => 'added', 'id' => $m['id'], 'mod' => $m['mod'], 'flags' => $m['flags']];
                }
                $state['lastHistoryId'] = $newHistoryId;
                if ($newHistoryId !== null) {
                    $this->updateHighWater($newHistoryId);
                }
                return $changes;
            }
            throw $e;
        }
        $changes = [];
        foreach ($records as $rec) {
            foreach (($rec['messagesAdded'] ?? []) as $entry) {
                $msg = $entry['message'] ?? [];
                $msgId = $msg['id'] ?? null;
                if (!$msgId) continue;
                $recordLabelIds = $msg['labelIds'] ?? null;
                if (!is_array($recordLabelIds)) continue;
                if (!$this->isInFolder($recordLabelIds, $folderLabelId, $isAllMail)) continue;
                try {
                    list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($msgId);
                } catch (GmailApiException $e) {
                    if ($e->httpStatus == 404) continue;
                    throw $e;
                }
                $stat = $this->computeStat($msgId, $curLabelIds, $curInternalDate);
                $changes[] = ['type' => 'added', 'id' => $msgId, 'mod' => $stat['mod'], 'flags' => $stat['flags']];
            }
            foreach (($rec['labelsAdded'] ?? []) as $entry) {
                $msg = $entry['message'] ?? [];
                $msgId = $msg['id'] ?? null;
                if (!$msgId) continue;
                $recordLabelIds = $msg['labelIds'] ?? null;
                if (!is_array($recordLabelIds)) continue;
                $addedLabelsForCheck = $entry['labelIds'] ?? [];
                if (!$isAllMail && in_array($folderLabelId, $addedLabelsForCheck, true)) {
                    try {
                        list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($msgId);
                    } catch (GmailApiException $e) {
                        if ($e->httpStatus == 404) continue;
                        throw $e;
                    }
                    $stat = $this->computeStat($msgId, $curLabelIds, $curInternalDate);
                    $changes[] = ['type' => 'added', 'id' => $msgId, 'mod' => $stat['mod'], 'flags' => $stat['flags']];
                } elseif ($this->isInFolder($recordLabelIds, $folderLabelId, $isAllMail)) {
                    try {
                        list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($msgId);
                    } catch (GmailApiException $e) {
                        if ($e->httpStatus == 404) continue;
                        throw $e;
                    }
                    $stat = $this->computeStat($msgId, $curLabelIds, $curInternalDate);
                    $changes[] = ['type' => 'changed', 'id' => $msgId, 'mod' => $stat['mod'], 'flags' => $stat['flags']];
                } else {
                    if ($isAllMail) {
                        $addedLabels = $entry['labelIds'] ?? [];
                        if (in_array('TRASH', $addedLabels, true) || in_array('SPAM', $addedLabels, true)) {
                            $changes[] = ['type' => 'deleted', 'id' => $msgId];
                        }
                    }
                }
            }
            foreach (($rec['labelsRemoved'] ?? []) as $entry) {
                $msg = $entry['message'] ?? [];
                $msgId = $msg['id'] ?? null;
                if (!$msgId) continue;
                $recordLabelIds = $msg['labelIds'] ?? null;
                if (!is_array($recordLabelIds)) continue;
                if ($this->isInFolder($recordLabelIds, $folderLabelId, $isAllMail)) {
                    try {
                        list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($msgId);
                    } catch (GmailApiException $e) {
                        if ($e->httpStatus == 404) continue;
                        throw $e;
                    }
                    $stat = $this->computeStat($msgId, $curLabelIds, $curInternalDate);
                    $changes[] = ['type' => 'changed', 'id' => $msgId, 'mod' => $stat['mod'], 'flags' => $stat['flags']];
                } else {
                    if (!$isAllMail) {
                        $removedLabels = $entry['labelIds'] ?? [];
                        if (in_array($folderLabelId, $removedLabels, true)) {
                            $changes[] = ['type' => 'deleted', 'id' => $msgId];
                        }
                    }
                }
            }
            $hasDetails = isset($rec['messagesAdded']) || isset($rec['messagesDeleted']) || isset($rec['labelsAdded']) || isset($rec['labelsRemoved']);
            if (!$hasDetails) {
                foreach (($rec['messages'] ?? []) as $m) {
                    $msgId = $m['id'] ?? null;
                    if (!$msgId) continue;
                    try {
                        list($curLabelIds, $curInternalDate) = $this->fetchMessageMetadata($msgId);
                    } catch (GmailApiException $e) {
                        if ($e->httpStatus == 404) {
                            $changes[] = ['type' => 'deleted', 'id' => $msgId];
                            continue;
                        }
                        throw $e;
                    }
                    if ($this->isInFolder($curLabelIds, $folderLabelId, $isAllMail)) {
                        $stat = $this->computeStat($msgId, $curLabelIds, $curInternalDate);
                        $changes[] = ['type' => 'changed', 'id' => $msgId, 'mod' => $stat['mod'], 'flags' => $stat['flags']];
                    }
                }
            }
        }
        if ($responseHistoryId !== null) {
            $state['lastHistoryId'] = $responseHistoryId;
            $this->updateHighWater($responseHistoryId);
        }
        return $changes;
    }

    private function getHighWater() {
        $file = defined('GMAIL_HIGHWATER_FILE') ? GMAIL_HIGHWATER_FILE : '/var/lib/z-push/.gmail-highwater.json';
        $content = @file_get_contents($file);
        if ($content === false) return null;
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['historyId'])) return null;
        return (string)$data['historyId'];
    }

    private function setHighWater($historyId) {
        $file = defined('GMAIL_HIGHWATER_FILE') ? GMAIL_HIGHWATER_FILE : '/var/lib/z-push/.gmail-highwater.json';
        $dir = dirname($file);
        if (!is_dir($dir)) return false;
        $tmp = $file . '.tmp.' . getmypid();
        $content = json_encode(['historyId' => (string)$historyId]);
        if (@file_put_contents($tmp, $content) === false) return false;
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function updateHighWater($historyId) {
        if ($historyId === null || $historyId === '') return;
        try {
            $cur = $this->getHighWater();
            if ($cur !== null && (int)$historyId <= (int)$cur) return;
            $ok = $this->setHighWater($historyId);
            if (!$ok) {
                ZLog::Write(LOGLEVEL_WARN, "BackendGmail: failed to write high-water file");
            }
        } catch (Throwable $e) {
            ZLog::Write(LOGLEVEL_WARN, "BackendGmail: high-water update failed");
        }
    }

    private function isInFolder($labelIds, $folderLabelId, $isAllMail) {
        if (!is_array($labelIds)) return false;
        if ($isAllMail) {
            return !in_array('TRASH', $labelIds, true) && !in_array('SPAM', $labelIds, true);
        }
        return in_array($folderLabelId, $labelIds, true);
    }
}
