<?php
// GmailApiClient batch + delete tests - plain PHP, no PHPUnit.
// Run (inside test image, no secrets needed - all stubbed):
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_api_batch_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. No real API calls.
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail_api.php';
if (!class_exists('GmailApiClient')) {
  echo "FAIL: setup - GmailApiClient class not found\n";
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
class StubTransportBatch {
  public $calls = [];
  private $script;
  private $index = 0;
  public function __construct($script) { $this->script = $script; }
  public function __invoke($method, $url, $headers, $body) {
    $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
    if ($this->index >= count($this->script)) {
      throw new Exception("Stub exhausted (call " . $this->index . ", url " . $url . ")");
    }
    $resp = $this->script[$this->index];
    $this->index++;
    return $resp;
  }
}
function makeTempCredsBatch($accessToken, $refreshToken, $expiresAt) {
  $tokenData = ['access_token' => $accessToken, 'refresh_token' => $refreshToken, 'expires_in' => 3600, 'expires_at' => $expiresAt];
  $tokenFile = tempnam(sys_get_temp_dir(), 'tok_');
  file_put_contents($tokenFile, json_encode($tokenData));
  $clientFile = tempnam(sys_get_temp_dir(), 'cli_');
  file_put_contents($clientFile, json_encode(['client_id' => 'fake-id.apps.googleusercontent.com', 'client_secret' => 'fake-secret']));
  return [$tokenFile, $clientFile];
}
function metaBody($id, $labels, $internalDate, $snippet) {
  $headers = [['name' => 'Subject', 'value' => 'Test ' . $id], ['name' => 'From', 'value' => 'a@example.com'], ['name' => 'Date', 'value' => 'Mon, 27 Oct 2026 10:00:00 +0000']];
  return json_encode(['id' => $id, 'labelIds' => $labels, 'internalDate' => $internalDate, 'snippet' => $snippet, 'payload' => ['headers' => $headers]]);
}
runTest('testBatchMetadataReturnsMapAndSkips404', function() {
  list($tf, $cf) = makeTempCredsBatch('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub = new StubTransportBatch([
      ['status' => 200, 'headers' => [], 'body' => metaBody('msg1', ['INBOX', 'UNREAD'], '1761560000000', 'hello one')],
      ['status' => 404, 'headers' => [], 'body' => '{"error": {"code": 404, "message": "Not Found"}}'],
      ['status' => 200, 'headers' => [], 'body' => metaBody('msg3', ['SENT'], '1761560001000', 'hello three')],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $out = $client->getMessagesMetadataBatch(['msg1', 'msg2gone', 'msg3']);
    assertTrue(is_array($out), "Batch must return array");
    assertTrue(isset($out['msg1']) && is_array($out['msg1']), "msg1 must have metadata");
    assertEquals($out['msg1']['labelIds'], ['INBOX', 'UNREAD'], "msg1 labelIds");
    assertEquals($out['msg1']['internalDate'], '1761560000000', "msg1 internalDate");
    assertEquals($out['msg1']['snippet'], 'hello one', "msg1 snippet");
    assertTrue(array_key_exists('msg2gone', $out) && $out['msg2gone'] === null, "404 id must map to null, not throw");
    assertTrue(isset($out['msg3']) && $out['msg3']['snippet'] === 'hello three', "msg3 snippet");
    assertEquals(count($stub->calls), 3, "Stub path must make one call per id");
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testBatchEmptyListMakesNoCalls', function() {
  list($tf, $cf) = makeTempCredsBatch('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub = new StubTransportBatch([]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $out = $client->getMessagesMetadataBatch([]);
    assertEquals($out, [], "Empty batch must return empty array");
    assertEquals(count($stub->calls), 0, "Empty batch must make zero HTTP calls");
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testBatchNon404ErrorPropagates', function() {
  list($tf, $cf) = makeTempCredsBatch('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub = new StubTransportBatch([
      ['status' => 500, 'headers' => [], 'body' => '{"error": {"code": 500}}'],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    try {
      $client->getMessagesMetadataBatch(['bad1']);
      throw new Exception("Expected GmailApiException for 500, got success");
    } catch (GmailApiException $e) {
      assertEquals($e->httpStatus, 500, "500 must propagate with httpStatus 500");
    }
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
runTest('testDeleteMessageUsesDeleteMethod', function() {
  list($tf, $cf) = makeTempCredsBatch('valid-access', 'refresh-unused', time() + 3600);
  try {
    $stub = new StubTransportBatch([
      ['status' => 200, 'headers' => [], 'body' => ''],
    ]);
    $client = new GmailApiClient($tf, $cf, $stub);
    $out = $client->deleteMessage('abc123');
    assertEquals($out, [], "Delete with empty body must return []");
    assertEquals(count($stub->calls), 1, "Delete must make exactly one call");
    assertEquals($stub->calls[0]['method'], 'DELETE', "Delete must use DELETE method");
    assertTrue(strpos($stub->calls[0]['url'], '/users/me/messages/abc123') !== false, "Delete URL must contain message id, got: " . $stub->calls[0]['url']);
  } finally {
    @unlink($tf); @unlink($cf);
  }
});
echo "batch tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
