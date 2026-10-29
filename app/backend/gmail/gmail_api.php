<?php
// GmailApiClient: minimal Gmail API v1 client for Z-Push BackendGmail.
// No Z-Push dependencies. HTTP via file_get_contents + stream_context (no curl dep).
// Token file: JSON with access_token, refresh_token, expires_in and/or expires_at.
// Client file: JSON with client_id, client_secret. token_uri defaults to https://oauth2.googleapis.com/token.
// All public methods return decoded arrays; empty body returns []. HTTP errors throw GmailApiException.
// Refresh failure message always contains "Gmail OAuth 刷新失败，需重新授权" with code 401 (plan expectation).

class GmailApiException extends Exception {
  public $httpStatus;
  public $retryAfter;
  public function __construct($message, $httpStatus = 0, $retryAfter = null) {
    parent::__construct($message, $httpStatus);
    $this->httpStatus = $httpStatus;
    $this->retryAfter = $retryAfter;
  }
}

class GmailApiClient {
  const API_BASE = 'https://gmail.googleapis.com/gmail/v1';
  const TOKEN_URI = 'https://oauth2.googleapis.com/token';
  private $tokenFile;
  private $clientFile;
  private $tokenData;
  private $clientData;
  private $accessToken;
  private $expiresAt;
  private $httpHandler;

  public function __construct($tokenFile, $clientFile = null, $httpHandler = null) {
    $this->tokenFile = $tokenFile;
    if ($clientFile === null) {
      $candidate = dirname($tokenFile) . '/.gmail-client.json';
      $this->clientFile = file_exists($candidate) ? $candidate : null;
    } else {
      $this->clientFile = $clientFile;
    }
    $this->httpHandler = $httpHandler;
    $this->loadTokens();
    $this->loadClient();
  }

  private function loadTokens() {
    if (!file_exists($this->tokenFile)) {
      throw new GmailApiException('Gmail 令牌文件不存在: ' . $this->tokenFile, 0);
    }
    $data = json_decode(file_get_contents($this->tokenFile), true);
    if (!is_array($data)) {
      throw new GmailApiException('Gmail 令牌文件 JSON 无效', 0);
    }
    $this->tokenData = $data;
    $this->accessToken = $data['access_token'] ?? '';
    if (isset($data['expires_at'])) {
      $this->expiresAt = (int)$data['expires_at'];
    } elseif (isset($data['expires_in'])) {
      $mtime = @filemtime($this->tokenFile);
      $this->expiresAt = ($mtime !== false ? (int)$mtime : time()) + (int)$data['expires_in'];
    } else {
      $this->expiresAt = 0;
    }
  }

  private function loadClient() {
    if ($this->clientFile === null || !file_exists($this->clientFile)) {
      throw new GmailApiException('Gmail OAuth 客户端配置文件缺失', 0);
    }
    $data = json_decode(file_get_contents($this->clientFile), true);
    if (!is_array($data) || empty($data['client_id']) || empty($data['client_secret'])) {
      throw new GmailApiException('Gmail OAuth 客户端配置文件无效（缺 client_id/secret）', 0);
    }
    $this->clientData = $data;
  }

  private function saveTokens() {
    $tmp = $this->tokenFile . '.tmp.' . getmypid();
    $json = json_encode($this->tokenData);
    if (file_put_contents($tmp, $json) === false) {
      throw new GmailApiException('无法写 Gmail 令牌文件', 0);
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $this->tokenFile)) {
      @unlink($tmp);
      throw new GmailApiException('无法更新 Gmail 令牌文件', 0);
    }
  }

  private function isExpired() {
    return empty($this->accessToken) || time() >= ($this->expiresAt - 60);
  }

  private function refresh() {
    $url = $this->tokenData['token_uri'] ?? self::TOKEN_URI;
    $body = http_build_query([
      'grant_type' => 'refresh_token',
      'client_id' => $this->clientData['client_id'],
      'client_secret' => $this->clientData['client_secret'],
      'refresh_token' => $this->tokenData['refresh_token'] ?? '',
    ]);
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    $resp = $this->http('POST', $url, $headers, $body);
    $data = json_decode($resp['body'] ?? '', true);
    if ($resp['status'] !== 200 || !is_array($data) || isset($data['error']) || empty($data['access_token'])) {
      throw new GmailApiException('Gmail OAuth 刷新失败，需重新授权', 401);
    }
    $this->tokenData['access_token'] = $data['access_token'];
    if (isset($data['expires_in'])) {
      $this->tokenData['expires_in'] = (int)$data['expires_in'];
    }
    $this->tokenData['expires_at'] = time() + (int)($data['expires_in'] ?? 3600);
    if (isset($data['scope'])) {
      $this->tokenData['scope'] = $data['scope'];
    }
    if (isset($data['token_type'])) {
      $this->tokenData['token_type'] = $data['token_type'];
    }
    if (!empty($data['refresh_token'])) {
      $this->tokenData['refresh_token'] = $data['refresh_token'];
    }
    $this->accessToken = $this->tokenData['access_token'];
    $this->expiresAt = $this->tokenData['expires_at'];
    $this->saveTokens();
    return true;
  }

  private function ensureToken() {
    if ($this->isExpired()) {
      $this->refresh();
    }
  }

  private function request($method, $url, $body = null, $isRetryAfterRefresh = false) {
    $this->ensureToken();
    $attempt429 = 0;
    while (true) {
      $headers = ['Authorization: Bearer ' . $this->accessToken];
      if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
      }
      $headers[] = 'Content-Length: ' . strlen($body ?? '');
      $resp = $this->http($method, $url, $headers, $body);
      $status = $resp['status'];
      $respHeaders = [];
      foreach (($resp['headers'] ?? []) as $k => $v) {
        $respHeaders[strtolower($k)] = $v;
      }
      if ($status === 401 && !$isRetryAfterRefresh) {
        $this->refresh();
        return $this->request($method, $url, $body, true);
      }
      if ($status === 401 && $isRetryAfterRefresh) {
        throw new GmailApiException('Gmail API 认证失败', 401);
      }
      if ($status === 429) {
        if ($attempt429 < 3) {
          $wait = 1;
          if (isset($respHeaders['retry-after'])) {
            $wait = (int)$respHeaders['retry-after'];
            if ($wait < 0) $wait = 0;
            if ($wait > 60) $wait = 60;
          }
          if ($wait > 0) sleep($wait);
          $attempt429++;
          continue;
        }
        throw new GmailApiException('Gmail API 限流 (429)', 429);
      }
      if ($status < 200 || $status >= 300) {
        $snippet = substr($resp['body'] ?? '', 0, 300);
        throw new GmailApiException('Gmail API error ' . $status . ': ' . $snippet, $status);
      }
      $b = $resp['body'] ?? '';
      if ($b === '' || $b === null) {
        return [];
      }
      $decoded = json_decode($b, true);
      if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new GmailApiException('Gmail API 返回非 JSON', $status);
      }
      return is_array($decoded) ? $decoded : [];
    }
  }

  private function http($method, $url, $headers, $body) {
    if ($this->httpHandler !== null && is_callable($this->httpHandler)) {
      $result = call_user_func($this->httpHandler, $method, $url, $headers, $body);
      $norm = [];
      foreach (($result['headers'] ?? []) as $k => $v) {
        $norm[strtolower($k)] = $v;
      }
      return ['status' => (int)($result['status'] ?? 0), 'headers' => $norm, 'body' => (string)($result['body'] ?? '')];
    }
    $opts = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => 15, 'ignore_errors' => true]];
    $opts['http']['content'] = ($body !== null ? $body : '');
    $context = stream_context_create($opts);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    $respHeaders = [];
    if (isset($http_response_header) && is_array($http_response_header)) {
      foreach ($http_response_header as $line) {
        if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $line, $m)) {
          $status = (int)$m[1];
        } elseif (strpos($line, ':') !== false) {
          list($k, $v) = explode(':', $line, 2);
          $respHeaders[strtolower(trim($k))] = trim($v);
        }
      }
    }
    if ($responseBody === false) {
      throw new GmailApiException('Gmail API 网络错误', 0);
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $responseBody];
  }

  private static function base64urlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  public function listLabels() {
    return $this->request('GET', self::API_BASE . '/users/me/labels');
  }

  public function getProfile() {
    return $this->request('GET', self::API_BASE . '/users/me/profile');
  }

  public function listMessages($labelId, $pageToken = null, $q = null) {
    $params = [];
    if ($labelId !== null) {
      $params['labelIds'] = $labelId;
    }
    if ($pageToken !== null && $pageToken !== '') {
      $params['pageToken'] = $pageToken;
    }
    if ($q !== null && $q !== '') {
      $params['q'] = $q;
    }
    $url = self::API_BASE . '/users/me/messages';
    if (!empty($params)) {
      $url .= '?' . http_build_query($params);
    }
    return $this->request('GET', $url);
  }

  public function getMessage($id, $format = 'full') {
    $url = self::API_BASE . '/users/me/messages/' . rawurlencode($id) . '?format=' . rawurlencode($format);
    return $this->request('GET', $url);
  }

  public function sendMessage($rawMime) {
    $body = json_encode(['raw' => self::base64urlEncode($rawMime)]);
    return $this->request('POST', self::API_BASE . '/users/me/messages/send', $body);
  }

  public function modifyMessage($id, $addLabels, $removeLabels) {
    $body = json_encode(['addLabelIds' => array_values($addLabels), 'removeLabelIds' => array_values($removeLabels)]);
    return $this->request('POST', self::API_BASE . '/users/me/messages/' . rawurlencode($id) . '/modify', $body);
  }

  public function trashMessage($id) {
    return $this->request('POST', self::API_BASE . '/users/me/messages/' . rawurlencode($id) . '/trash', null);
  }

  public function deleteMessage($id) {
    return $this->request('DELETE', self::API_BASE . '/users/me/messages/' . rawurlencode($id));
  }

  // Parallel metadata fetch for a list of ids.
  // Returns [id => ['labelIds'=>[], 'internalDate'=>string, 'snippet'=>string, 'headers'=>[['name'=>..,'value'=>..],..]] | null(404)].
  // Throws on persistent non-404 errors (same semantics as sequential fetch).
  // If an httpHandler stub is injected (tests), falls back to sequential request()
  // calls so stub transports keep working; real transport uses curl_multi.
  public function getMessagesMetadataBatch($ids, $concurrency = 10) {
    $ids = array_values(array_unique(array_map('strval', (array)$ids)));
    if (empty($ids)) {
      return [];
    }
    if ($this->httpHandler !== null) {
      $out = [];
      foreach ($ids as $id) {
        try {
          $r = $this->getMessage($id, 'metadata');
          $out[$id] = [
            'labelIds' => $r['labelIds'] ?? [],
            'internalDate' => (string)($r['internalDate'] ?? '0'),
            'snippet' => (string)($r['snippet'] ?? ''),
            'headers' => $this->extractHeaders($r),
          ];
        } catch (GmailApiException $e) {
          if ($e->httpStatus == 404) {
            $out[$id] = null;
          } else {
            throw $e;
          }
        }
      }
      return $out;
    }
    return $this->getMessagesMetadataBatchParallel($ids, $concurrency, false);
  }

  private function extractHeaders($result) {
    $headers = [];
    $list = $result['payload']['headers'] ?? [];
    if (is_array($list)) {
      foreach ($list as $h) {
        if (isset($h['name']) && isset($h['value'])) {
          $headers[] = ['name' => (string)$h['name'], 'value' => (string)$h['value']];
        }
      }
    }
    return $headers;
  }

  private function getMessagesMetadataBatchParallel($ids, $concurrency, $retriedAfterRefresh) {
    $this->ensureToken();
    $concurrency = max(1, min(20, (int)$concurrency));
    $mh = curl_multi_init();
    $handles = [];
    $results = [];
    $queue = $ids;
    $next = 0;
    $active = null;
    $makeHandle = function($id) {
      $url = self::API_BASE . '/users/me/messages/' . rawurlencode($id) . '?format=metadata';
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 15);
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->accessToken, 'Accept: application/json', 'Content-Length: 0']);
      return $ch;
    };
    $total = count($ids);
    while ($next < $total && count($handles) < $concurrency) {
      $id = $queue[$next++];
      $ch = $makeHandle($id);
      $handles[spl_object_id($ch)] = ['ch' => $ch, 'id' => $id];
      curl_multi_add_handle($mh, $ch);
    }
    do {
      $mrc = curl_multi_exec($mh, $active);
      if ($mrc !== CURLM_OK) {
        break;
      }
      if ($active) {
        curl_multi_select($mh, 1.0);
      }
      while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $key = spl_object_id($ch);
        if (!isset($handles[$key])) {
          continue;
        }
        $id = $handles[$key]['id'];
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($handles[$key]);
        if ($errno !== 0) {
          $results[$id] = ['__error__' => true, 'status' => 0];
        } elseif ($status === 200) {
          $data = json_decode($body === false ? '' : $body, true);
          if (!is_array($data)) {
            $results[$id] = ['__error__' => true, 'status' => $status];
          } else {
            $results[$id] = [
              'labelIds' => $data['labelIds'] ?? [],
              'internalDate' => (string)($data['internalDate'] ?? '0'),
              'snippet' => (string)($data['snippet'] ?? ''),
              'headers' => $this->extractHeaders($data),
            ];
          }
        } elseif ($status === 404) {
          $results[$id] = null;
        } elseif ($status === 401) {
          $results[$id] = ['__error__' => true, 'status' => 401];
        } elseif ($status === 429) {
          $results[$id] = ['__error__' => true, 'status' => 429];
        } else {
          $results[$id] = ['__error__' => true, 'status' => $status];
        }
        if ($next < $total) {
          $nid = $queue[$next++];
          $nch = $makeHandle($nid);
          $handles[spl_object_id($nch)] = ['ch' => $nch, 'id' => $nid];
          curl_multi_add_handle($mh, $nch);
        }
      }
    } while ($active || !empty($handles));
    curl_multi_close($mh);
    // Whole-batch 401: refresh once and retry (mirrors request()).
    $has401 = false;
    foreach ($results as $r) {
      if (is_array($r) && isset($r['__error__']) && $r['status'] === 401) { $has401 = true; break; }
    }
    if ($has401 && !$retriedAfterRefresh) {
      $this->refresh();
      return $this->getMessagesMetadataBatchParallel($ids, $concurrency, true);
    }
    if ($has401 && $retriedAfterRefresh) {
      throw new GmailApiException('Gmail API 认证失败', 401);
    }
    // Sequential retry for per-id transient/transport errors (429 backoff lives
    // in request()). Persistent errors throw here, preserving sequential semantics.
    $out = [];
    foreach ($ids as $id) {
      $r = $results[$id] ?? ['__error__' => true, 'status' => 0];
      if (is_array($r) && isset($r['__error__'])) {
        $meta = $this->getMessage($id, 'metadata');
        $out[$id] = [
          'labelIds' => $meta['labelIds'] ?? [],
          'internalDate' => (string)($meta['internalDate'] ?? '0'),
          'snippet' => (string)($meta['snippet'] ?? ''),
          'headers' => $this->extractHeaders($meta),
        ];
      } else {
        $out[$id] = $r;
      }
    }
    return $out;
  }

  public function listHistory($startHistoryId, $pageToken = null, $historyTypes = null) {
    $url = self::API_BASE . '/users/me/history?startHistoryId=' . rawurlencode($startHistoryId);
    if ($pageToken !== null && $pageToken !== '') {
      $url .= '&pageToken=' . rawurlencode($pageToken);
    }
    if (is_array($historyTypes)) {
      foreach ($historyTypes as $t) {
        $url .= '&historyTypes=' . rawurlencode($t);
      }
    }
    return $this->request('GET', $url);
  }

  public function watchMailbox($topicName) {
    $body = json_encode(['topicName' => $topicName]);
    return $this->request('POST', self::API_BASE . '/users/me/watch', $body);
  }

  public function stopWatch() {
    return $this->request('POST', self::API_BASE . '/users/me/stop', null);
  }
}
