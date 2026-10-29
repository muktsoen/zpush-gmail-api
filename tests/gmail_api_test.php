<?php
// GmailApiClient tests - plain PHP, no PHPUnit.
// Run: php /opt/zpush-gmail/tests/gmail_api_test.php [tokenFile] [clientFile]
// Exit 0 if all pass, 1 otherwise. Prints PASS/FAIL per test. No secrets printed.
$tokenFile = $argv[1] ?? '/opt/YOUR_GCP_PROJECT_ID/.gmail-oauth.json';
$clientFile = $argv[2] ?? '/opt/YOUR_GCP_PROJECT_ID/.gmail-client.json';
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail_api.php';
if (!class_exists('GmailApiClient')) {
  echo "FAIL: setup - GmailApiClient class not found (client file missing)\n";
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
function assertEquals($a, $b, $msg) {
  if ($a !== $b) throw new Exception($msg . " (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")");
}
class StubTransport {
  public $calls = [];
  private $script;
  private $index = 0;
  public function __construct($script) { $this->script = $script; }
  public function __invoke($method, $url, $headers, $body) {
    $this->calls[] = ['method' => $method, 'url' => $url];
    if ($this->index >= count($this->script)) {
      throw new Exception("Stub exhausted (call " . $this->index . ", url " . $url . ")");
    }
    $resp = $this->script[$this->index];
    $this->index++;
    return $resp;
  }
}
function makeTempCreds($accessToken, $refreshToken, $expiresAt) {
  $tokenData = ['access_token' => $accessToken, 'refresh_token' => $refreshToken, 'expires_in' => 3600, 'expires_at' => $expiresAt];
  $tokenFile = tempnam(sys_get_temp_dir(), 'tok_');
  file_put_contents($tokenFile, json_encode($tokenData));
  $clientFile = tempnam(sys_get_temp_dir(), 'cli_');
  file_put_contents($clientFile, json_encode(['client_id' => 'fake-id.apps.googleusercontent.com', 'client_secret' => 'fake-secret']));
  return [$tokenFile, $clientFile];
}
runTest('testTokenRefreshFailure', function() {
  // Token locally valid (future expiry) so API call happens first and returns 401,
  // then refresh is attempted and fails. This matches the API-401 -> refresh flow.
  list($tf, $cf) = makeTempCreds('valid-access', 'revoked-refresh', time() + 3600);
  try {
    $stub = new StubTransport([
      ['status' => 401, 'headers' => [], 'body' => '{"error": {"code": 401, "message": "Request had invalid authentication credentials."}}'],
      ['status' => 401, 'headers' => [], 'body' => '{"error": "invalid_grant", "error_description": "Token has been expired or revoked."}'],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    try {
      $client->listLabels();
      throw new Exception("Expected GmailApiException, got success");
    } catch (GmailApiException $e) {
      assertTrue($e->getCode() === 401, "Expected 401, got " . $e->getCode());
      assertTrue(strpos($e->getMessage(), 'Gmail OAuth 刷新失败，需重新授权') !== false, "Message must contain refresh failure hint, got: " . $e->getMessage());
    }
    assertEquals(count($stub->calls), 2, "Expected exactly 2 HTTP calls (API + refresh, no loop)");
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testRefreshSuccessStub', function() {
  // Token locally valid, API returns 401, refresh succeeds, API retry succeeds.
  list($tf, $cf) = makeTempCreds('old-access', 'good-refresh', time() + 3600);
  try {
    $stub = new StubTransport([
      ['status' => 401, 'headers' => [], 'body' => '{"error": {"code": 401}}'],
      ['status' => 200, 'headers' => [], 'body' => '{"access_token": "new-access-123", "expires_in": 3600, "scope": "https://www.googleapis.com/auth/gmail.modify", "token_type": "Bearer"}'],
      ['status' => 200, 'headers' => [], 'body' => '{"labels": [{"id": "INBOX", "name": "INBOX", "type": "system"}]}'],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $result = $client->listLabels();
    assertTrue(isset($result['labels']), "Expected labels in result");
    assertEquals(count($stub->calls), 3, "Expected 3 calls (API 401, refresh, API retry)");
    $saved = json_decode(file_get_contents($tf), true);
    assertEquals($saved['access_token'], 'new-access-123', "Token file should have new access_token");
    assertEquals($saved['refresh_token'], 'good-refresh', "Refresh token should be preserved when response lacks new one");
    assertTrue(isset($saved['expires_at']) && $saved['expires_at'] > time(), "Token file should have future expires_at");
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testExpiredTokenRefreshesFirst', function() {
  // Token locally expired: client refreshes BEFORE first API call, then API succeeds.
  list($tf, $cf) = makeTempCreds('old-access', 'good-refresh', time() - 100);
  try {
    $stub = new StubTransport([
      ['status' => 200, 'headers' => [], 'body' => '{"access_token": "fresh-access-456", "expires_in": 3600, "scope": "https://www.googleapis.com/auth/gmail.modify", "token_type": "Bearer"}'],
      ['status' => 200, 'headers' => [], 'body' => '{"labels": [{"id": "INBOX", "name": "INBOX"}]}'],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $result = $client->listLabels();
    assertTrue(isset($result['labels']), "Expected labels after expired-first refresh");
    assertEquals(count($stub->calls), 2, "Expected 2 calls (refresh first, then API)");
    $saved = json_decode(file_get_contents($tf), true);
    assertEquals($saved['access_token'], 'fresh-access-456', "Token file should have fresh access_token");
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testRateLimitBackoffStub', function() {
  list($tf, $cf) = makeTempCreds('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub = new StubTransport([
      ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error": {"code": 429}}'],
      ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error": {"code": 429}}'],
      ['status' => 200, 'headers' => [], 'body' => '{"labels": [{"id": "INBOX", "name": "INBOX"}]}'],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $result = $client->listLabels();
    assertTrue(isset($result['labels']), "Expected labels after retries");
    assertEquals(count($stub->calls), 3, "Expected 3 calls (429, 429, 200)");
  } finally {
    @unlink($tf); @unlink($cf);
  }
  list($tf2, $cf2) = makeTempCreds('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub2 = new StubTransport([
      ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error": {"code": 429}}'],
      ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error": {"code": 429}}'],
      ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error": {"code": 429}}'],
      ['status' => 429, 'headers' => ['retry-after' => '0'], 'body' => '{"error": {"code": 429}}'],
    ]);
    $client2 = new GmailApiClient($tf2, $cf2, $stub2);
    try {
      $client2->listLabels();
      throw new Exception("Expected GmailApiException on persistent 429");
    } catch (GmailApiException $e) {
      assertTrue($e->getCode() === 429, "Expected 429, got " . $e->getCode());
    }
    assertTrue(count($stub2->calls) <= 4, "Should not retry forever (calls=" . count($stub2->calls) . ")");
  } finally {
    @unlink($tf2); @unlink($cf2);
  }
});
runTest('testWatchStopStub', function() {
  list($tf, $cf) = makeTempCreds('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub = new StubTransport([
      ['status' => 200, 'headers' => [], 'body' => '{"historyId": "12345", "expiration": "9999999999999"}'],
      ['status' => 204, 'headers' => [], 'body' => ''],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $watch = $client->watchMailbox('projects/fake-project/topics/gmail-push');
    assertEquals($watch['historyId'], '12345', "Watch should return historyId");
    $stop = $client->stopWatch();
    assertTrue($stop === [], "stopWatch should return empty array on 204");
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testGetProfileReal', function() use ($tokenFile, $clientFile) {
  $client = new GmailApiClient($tokenFile, $clientFile);
  $profile = $client->getProfile();
  assertTrue(isset($profile['historyId']) && is_numeric($profile['historyId']), "Profile must have numeric historyId");
  assertTrue(!empty($profile['emailAddress']), "Profile must have emailAddress");
});
runTest('testLabelsReal', function() use ($tokenFile, $clientFile) {
  $client = new GmailApiClient($tokenFile, $clientFile);
  $result = $client->listLabels();
  assertTrue(isset($result['labels']) && is_array($result['labels']), "listLabels must return labels array");
  $names = array_column($result['labels'], 'name');
  assertTrue(in_array('INBOX', $names), "INBOX must be in labels");
  assertTrue(count($names) >= 5, "Expected >=5 labels, got " . count($names));
});
runTest('testLabelsAndMessageRoundTripReal', function() use ($tokenFile, $clientFile) {
  $client = new GmailApiClient($tokenFile, $clientFile);
  $list = $client->listMessages('INBOX');
  assertTrue(isset($list['messages']) && count($list['messages']) >= 1, "INBOX must have >=1 message");
  $subject = '[GmailApiClient Test] ' . date('Y-m-d H:i:s');
  $bodyText = 'GmailApiClient roundtrip test - please ignore, will be trashed by test.';
  $rawMime = "To: YOUR_GMAIL_ADDRESS\r\nSubject: $subject\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$bodyText\r\n";
  $sent = $client->sendMessage($rawMime);
  assertTrue(!empty($sent['id']), "sendMessage must return id");
  $testId = $sent['id'];
  try {
    $msgRaw = $client->getMessage($testId, 'raw');
    assertTrue(!empty($msgRaw['raw']), "getMessage raw must have raw field");
    $raw = strtr($msgRaw['raw'], '-_', '+/');
    $pad = strlen($raw) % 4;
    if ($pad) $raw .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($raw);
    assertTrue(strpos($decoded, $subject) !== false, "Decoded MIME must contain test subject");
    assertTrue(strpos($decoded, 'Content-Type:') !== false, "Decoded MIME must contain Content-Type header");
    $msgFull = $client->getMessage($testId, 'full');
    $origLabels = $msgFull['labelIds'] ?? [];
    $origUnread = in_array('UNREAD', $origLabels);
    if ($origUnread) {
      $client->modifyMessage($testId, [], ['UNREAD']);
    } else {
      $client->modifyMessage($testId, ['UNREAD'], []);
    }
    $msgAfter = $client->getMessage($testId, 'full');
    $afterUnread = in_array('UNREAD', $msgAfter['labelIds'] ?? []);
    assertTrue($afterUnread !== $origUnread, "UNREAD state must flip after modify");
    if ($origUnread) {
      $client->modifyMessage($testId, ['UNREAD'], []);
    } else {
      $client->modifyMessage($testId, [], ['UNREAD']);
    }
    $msgRestored = $client->getMessage($testId, 'full');
    $restoredUnread = in_array('UNREAD', $msgRestored['labelIds'] ?? []);
    assertTrue($restoredUnread === $origUnread, "UNREAD state must restore to original");
  } finally {
    try { $client->trashMessage($testId); } catch (Throwable $e) { echo "  (cleanup trash note: " . $e->getMessage() . ")\n"; }
  }
  $msgTrashed = $client->getMessage($testId, 'full');
  assertTrue(in_array('TRASH', $msgTrashed['labelIds'] ?? []), "Test message must be in TRASH after cleanup");
});
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
