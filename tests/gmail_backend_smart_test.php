<?php
// BackendGmail SmartReply/Forward tests (Task 6 remainder) - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets:/var/lib/z-push zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_smart_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. No message is permanently deleted (cleanup is trash only).
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
define('GMAIL_LOGON_IMAP_PROBE', false);
define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH'); // Test-only hash for dummy-probe-disabled; production hash in config.php is untouched.
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) {
  echo "FAIL: setup - BackendGmail class not found (gmail.php missing)\n";
  exit(1);
}
$failures = 0;
$passed = 0;
function runTest($name, $fn) {
  global $failures, $passed;
  try {
    $fn();
    $passed++;
    echo "PASS: $name\n";
  } catch (Throwable $e) {
    $failures++;
    echo "FAIL: $name - " . $e->getMessage() . "\n";
  }
}
function assertTrue($cond, $msg) {
  if (!$cond) throw new Exception($msg);
}
function readAsbodyData($data) {
  if (is_resource($data)) {
    rewind($data);
    return stream_get_contents($data);
  }
  if (is_object($data) && method_exists($data, '__toString')) {
    return (string)$data;
  }
  return (string)$data;
}
function newBackend() {
  $b = new BackendGmail();
  $ok = $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  assertTrue($ok === true, "Logon must succeed with probe disabled");
  return $b;
}
function newApiClient() {
  return new GmailApiClient('/var/lib/z-push/.gmail-oauth.json', '/var/lib/z-push/.gmail-client.json');
}
function apiLabelIds($api, $id) {
  $m = $api->getMessage($id, 'metadata');
  return $m['labelIds'] ?? [];
}
function parseRawHeadersTest($rawMime) {
  $posCRLF = strpos($rawMime, "\r\n\r\n");
  $posLF = strpos($rawMime, "\n\n");
  $pos = false;
  if ($posCRLF !== false && ($posLF === false || $posCRLF <= $posLF)) { $pos = $posCRLF; $len = 4; }
  elseif ($posLF !== false) { $pos = $posLF; $len = 2; }
  else { $len = 0; }
  $block = ($pos === false) ? $rawMime : substr($rawMime, 0, $pos);
  $block = preg_replace("/\r\n[ \t]+/", " ", $block);
  $block = preg_replace("/\n[ \t]+/", " ", $block);
  $lines = preg_split("/\r\n|\n/", $block);
  $out = [];
  foreach ($lines as $line) {
    $colon = strpos($line, ':');
    if ($colon === false) continue;
    $name = strtolower(trim(substr($line, 0, $colon)));
    $value = trim(substr($line, $colon + 1));
    if ($name === '' || isset($out[$name])) continue;
    $out[$name] = $value;
  }
  return $out;
}
function buildOrigMime($subject, $withAttach) {
  $bOuter = 'smartorigouter1';
  $bAlt = 'smartorigalt2';
  $lines = [];
  $lines[] = 'From: YOUR_GMAIL_ADDRESS';
  $lines[] = 'To: YOUR_GMAIL_ADDRESS';
  $lines[] = 'Subject: ' . $subject;
  $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
  $lines[] = 'MIME-Version: 1.0';
  if ($withAttach) {
    $lines[] = 'Content-Type: multipart/mixed; boundary="' . $bOuter . '"';
    $lines[] = '';
    $lines[] = '--' . $bOuter;
    $lines[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
    $lines[] = '';
    $lines[] = "Original plain body line1\nOriginal plain body line2";
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/html; charset="UTF-8"';
    $lines[] = '';
    $lines[] = '<p>Original HTML body</p>';
    $lines[] = '';
    $lines[] = '--' . $bAlt . '--';
    $lines[] = '';
    $lines[] = '--' . $bOuter;
    $lines[] = 'Content-Type: text/plain; name="orig-attach.txt"';
    $lines[] = 'Content-Disposition: attachment; filename="orig-attach.txt"';
    $lines[] = 'Content-Transfer-Encoding: base64';
    $lines[] = '';
    $lines[] = base64_encode('Original attachment content');
    $lines[] = '';
    $lines[] = '--' . $bOuter . '--';
    $lines[] = '';
  } else {
    $lines[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
    $lines[] = '';
    $lines[] = "Original plain body line1\nOriginal plain body line2";
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/html; charset="UTF-8"';
    $lines[] = '';
    $lines[] = '<p>Original HTML body</p>';
    $lines[] = '';
    $lines[] = '--' . $bAlt . '--';
    $lines[] = '';
  }
  return implode("\r\n", $lines);
}
function buildNewMime($subject, $plainBody, $htmlBody, $attachName, $attachContent) {
  $bOuter = 'smartnewouter3';
  $bAlt = 'smartnewalt4';
  $lines = [];
  $lines[] = 'From: YOUR_GMAIL_ADDRESS';
  $lines[] = 'To: YOUR_GMAIL_ADDRESS';
  $lines[] = 'Subject: ' . $subject;
  $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
  $lines[] = 'MIME-Version: 1.0';
  if ($attachName !== null) {
    $lines[] = 'Content-Type: multipart/mixed; boundary="' . $bOuter . '"';
    $lines[] = '';
    $lines[] = '--' . $bOuter;
    $lines[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
    $lines[] = '';
    $lines[] = $plainBody;
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/html; charset="UTF-8"';
    $lines[] = '';
    $lines[] = $htmlBody;
    $lines[] = '';
    $lines[] = '--' . $bAlt . '--';
    $lines[] = '';
    $lines[] = '--' . $bOuter;
    $lines[] = 'Content-Type: text/plain; name="' . $attachName . '"';
    $lines[] = 'Content-Disposition: attachment; filename="' . $attachName . '"';
    $lines[] = 'Content-Transfer-Encoding: base64';
    $lines[] = '';
    $lines[] = base64_encode($attachContent);
    $lines[] = '';
    $lines[] = '--' . $bOuter . '--';
    $lines[] = '';
  } else {
    $lines[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
    $lines[] = '';
    $lines[] = $plainBody;
    $lines[] = '';
    $lines[] = '--' . $bAlt;
    $lines[] = 'Content-Type: text/html; charset="UTF-8"';
    $lines[] = '';
    $lines[] = $htmlBody;
    $lines[] = '';
    $lines[] = '--' . $bAlt . '--';
    $lines[] = '';
  }
  return implode("\r\n", $lines);
}
function findSentBySubject($backend, $subject) {
  $cp = new ContentParameters();
  $cutoff = time() - 3600;
  $list = $backend->GetMessageList('SENT', $cutoff);
  foreach ($list as $entry) {
    try { $msg = $backend->GetMessage('SENT', $entry['id'], $cp); } catch (Throwable $e) { continue; }
    if ($msg->subject === $subject) {
      return [$entry['id'], $msg];
    }
  }
  return [null, null];
}
function getBodiesFromRaw($raw) {
  $mobj = new Mail_mimeDecode($raw);
  $dec = $mobj->decode(['decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'rfc_822bodies' => true, 'charset' => 'utf-8']);
  $plain = ''; $html = '';
  Mail_mimeDecode::getBodyRecursive($dec, 'plain', $plain);
  Mail_mimeDecode::getBodyRecursive($dec, 'html', $html);
  return [$plain, $html, $dec];
}
runTest('testSmartReply', function() {
  $api = newApiClient();
  $backend = newBackend();
  $ts = gmdate('YmdHis');
  $origSubject = '[BackendGmail Test] SmartReply Orig ' . $ts;
  $orig = $api->sendMessage(buildOrigMime($origSubject, false));
  $origId = $orig['id'] ?? '';
  assertTrue($origId !== '', "orig send must return id");
  $sentId = null;
  try {
    $origRaw = readAsbodyData($backend->GetMessage('INBOX', $origId, new ContentParameters())->asbody->data);
    $origHeaders = parseRawHeadersTest($origRaw);
    $origMsgId = $origHeaders['message-id'] ?? '';
    assertTrue($origMsgId !== '', "orig must have Message-ID (Gmail generated)");
    $replySubject = 'Re: ' . $origSubject;
    $sm = new SyncSendMail();
    $sm->mime = buildNewMime($replySubject, 'Reply new plain', '<p>Reply new HTML</p>', 'reply-attach.txt', 'Reply attachment content');
    $sm->source = new SyncSendMailSource();
    $sm->source->folderid = 'INBOX';
    $sm->source->itemid = $origId;
    $sm->replyflag = true;
    $ok = $backend->SendMail($sm);
    assertTrue($ok === true, "SmartReply SendMail must return true");
    list($foundId, $foundMsg) = findSentBySubject($backend, $replySubject);
    assertTrue($foundId !== null, "Reply with subject must be found in SENT");
    $sentId = $foundId;
    $sentRaw = readAsbodyData($foundMsg->asbody->data);
    assertTrue(strpos($sentRaw, 'In-Reply-To: ' . $origMsgId) !== false, "Reply raw must contain In-Reply-To with orig Message-ID");
    assertTrue(strpos($sentRaw, 'References: ' . $origMsgId) !== false, "Reply raw must contain References with orig Message-ID");
    list($plain, $html, $dec) = getBodiesFromRaw($sentRaw);
    assertTrue(strpos($plain, 'Reply new plain') !== false, "Reply plain must contain new plain");
    assertTrue(strpos($plain, '> Original plain body line1') !== false, "Reply plain must contain quoted orig line1 with '> ' prefix");
    assertTrue(strpos($html, 'Reply new HTML') !== false, "Reply html must contain new html");
    assertTrue(strpos($html, '<blockquote>') !== false, "Reply html must contain blockquote");
    assertTrue(strpos($html, 'Original HTML body') !== false, "Reply html must contain quoted orig html");
    assertTrue(strpos($sentRaw, 'reply-attach.txt') !== false, "Reply raw must contain new attachment filename");
    assertTrue(strpos($sentRaw, base64_encode('Reply attachment content')) !== false, "Reply raw must contain new attachment content base64");
    $backend->Logoff();
  } finally {
    try { $labels = apiLabelIds($api, $origId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($origId); } catch (Throwable $e) {}
    if ($sentId !== null) { try { $labels = apiLabelIds($api, $sentId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($sentId); } catch (Throwable $e) {} }
  }
});
runTest('testSmartForward', function() {
  $api = newApiClient();
  $backend = newBackend();
  $ts = gmdate('YmdHis');
  $origSubject = '[BackendGmail Test] SmartForward Orig ' . $ts;
  $orig = $api->sendMessage(buildOrigMime($origSubject, true));
  $origId = $orig['id'] ?? '';
  assertTrue($origId !== '', "orig send must return id");
  $sentId = null;
  try {
    $fwdSubject = 'Fwd: ' . $origSubject;
    $sm = new SyncSendMail();
    $sm->mime = buildNewMime($fwdSubject, 'Forward new plain', '<p>Forward new HTML</p>', 'forward-new-attach.txt', 'Forward new attachment');
    $sm->source = new SyncSendMailSource();
    $sm->source->folderid = 'INBOX';
    $sm->source->itemid = $origId;
    $sm->forwardflag = true;
    $ok = $backend->SendMail($sm);
    assertTrue($ok === true, "SmartForward SendMail must return true");
    list($foundId, $foundMsg) = findSentBySubject($backend, $fwdSubject);
    assertTrue($foundId !== null, "Forward with subject must be found in SENT");
    $sentId = $foundId;
    $sentRaw = readAsbodyData($foundMsg->asbody->data);
    assertTrue(strpos($sentRaw, 'In-Reply-To:') === false, "Forward raw must NOT contain In-Reply-To (forward is new thread)");
    assertTrue(strpos($sentRaw, 'References:') === false, "Forward raw must NOT contain References");
    assertTrue(strpos($sentRaw, 'forwarded_message.eml') !== false, "Forward raw must contain forwarded_message.eml attachment");
    assertTrue(strpos($sentRaw, 'forward-new-attach.txt') !== false, "Forward raw must contain new attachment filename");
    list($plain, $html, $dec) = getBodiesFromRaw($sentRaw);
    assertTrue(strpos($plain, 'Forward new plain') !== false, "Forward plain must contain new plain");
    assertTrue(strpos($plain, 'Original plain body line1') === false, "Forward plain (via getBodyRecursive, skipping eml) must NOT contain orig plain inline");
    $emlBody = null;
    $stack = [$dec];
    while (!empty($stack)) {
      $part = array_pop($stack);
      if (isset($part->parts) && is_array($part->parts)) { foreach ($part->parts as $sp) $stack[] = $sp; }
      $dparams = $part->d_parameters ?? [];
      $fname = $dparams['filename'] ?? '';
      if ($fname === 'forwarded_message.eml' && isset($part->body)) { $emlBody = $part->body; break; }
    }
    assertTrue($emlBody !== null, "Forward eml attachment body must be found via decode");
    assertTrue(strpos($emlBody, 'Original plain body line1') !== false, "Eml body must contain orig plain");
    assertTrue(strpos($emlBody, 'orig-attach.txt') !== false, "Eml body must contain orig attachment filename");
    $backend->Logoff();
  } finally {
    try { $labels = apiLabelIds($api, $origId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($origId); } catch (Throwable $e) {}
    if ($sentId !== null) { try { $labels = apiLabelIds($api, $sentId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($sentId); } catch (Throwable $e) {} }
  }
});
runTest('testSmartReplySourceMissingFallback', function() {
  $api = newApiClient();
  $backend = newBackend();
  $ts = gmdate('YmdHis');
  $subject = '[BackendGmail Test] SmartReply Fallback ' . $ts;
  $sentId = null;
  try {
    $sm = new SyncSendMail();
    $sm->mime = buildNewMime($subject, 'Fallback new plain', '<p>Fallback new HTML</p>', null, '');
    $sm->source = new SyncSendMailSource();
    $sm->source->folderid = 'INBOX';
    $sm->source->itemid = 'nonexistent1234567890abcdef';
    $sm->replyflag = true;
    $ok = $backend->SendMail($sm);
    assertTrue($ok === true, "Fallback SendMail must return true (not throw on missing source)");
    list($foundId, $foundMsg) = findSentBySubject($backend, $subject);
    assertTrue($foundId !== null, "Fallback message must be found in SENT");
    $sentId = $foundId;
    $sentRaw = readAsbodyData($foundMsg->asbody->data);
    assertTrue(strpos($sentRaw, 'Fallback new plain') !== false, "Fallback raw must contain new plain (delivered as-is)");
    $backend->Logoff();
  } finally {
    if ($sentId !== null) { try { $labels = apiLabelIds($api, $sentId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($sentId); } catch (Throwable $e) {} }
  }
});
runTest('testSmartReplaceMimeBypass', function() {
  $api = newApiClient();
  $backend = newBackend();
  $ts = gmdate('YmdHis');
  $origSubject = '[BackendGmail Test] SmartReplace Orig ' . $ts;
  $orig = $api->sendMessage(buildOrigMime($origSubject, false));
  $origId = $orig['id'] ?? '';
  assertTrue($origId !== '', "orig send must return id");
  $sentId = null;
  try {
    $subject = 'Re: ' . $origSubject;
    $sm = new SyncSendMail();
    $sm->mime = buildNewMime($subject, 'Replace new plain', '<p>Replace new HTML</p>', null, '');
    $sm->source = new SyncSendMailSource();
    $sm->source->folderid = 'INBOX';
    $sm->source->itemid = $origId;
    $sm->replyflag = true;
    $sm->replacemime = true;
    $ok = $backend->SendMail($sm);
    assertTrue($ok === true, "ReplaceMime SendMail must return true");
    list($foundId, $foundMsg) = findSentBySubject($backend, $subject);
    assertTrue($foundId !== null, "ReplaceMime message must be found in SENT");
    $sentId = $foundId;
    $sentRaw = readAsbodyData($foundMsg->asbody->data);
    assertTrue(strpos($sentRaw, 'Replace new plain') !== false, "ReplaceMime raw must contain new plain");
    assertTrue(strpos($sentRaw, 'Original plain body line1') === false, "ReplaceMime raw must NOT contain quoted orig (bypass merging)");
    assertTrue(strpos($sentRaw, 'In-Reply-To:') === false, "ReplaceMime raw must NOT contain In-Reply-To (bypass)");
    $backend->Logoff();
  } finally {
    try { $labels = apiLabelIds($api, $origId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($origId); } catch (Throwable $e) {}
    if ($sentId !== null) { try { $labels = apiLabelIds($api, $sentId); if (!in_array('TRASH', $labels, true)) $api->trashMessage($sentId); } catch (Throwable $e) {} }
  }
});
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
