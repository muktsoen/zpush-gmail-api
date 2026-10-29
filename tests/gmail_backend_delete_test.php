<?php
// BackendGmail delete / empty-folder / snippet-preview tests - plain PHP, no PHPUnit.
// Run (inside test image, all stubbed, no real API, no secrets):
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_delete_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed.
// NOTE: These tests inject a stub GmailApiClient via a subclass overriding
// ensureApi(). That requires ensureApi() to be protected (not private).
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
define('GMAIL_LOGON_IMAP_PROBE', false);
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) {
  echo "FAIL: setup - BackendGmail class not found\n";
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
class StubApiDelete {
  public $calls = [];
  private $meta; // id => [labelIds, internalDate, snippet, headers]
  private $listByLabel; // label => [ids]
  public function __construct($meta, $listByLabel) { $this->meta = $meta; $this->listByLabel = $listByLabel; }
  public function listLabels() {
    $labels = [];
    foreach (['INBOX','SENT','DRAFT','TRASH','SPAM'] as $id) { $labels[] = ['id' => $id, 'name' => $id, 'type' => 'system']; }
    return ['labels' => $labels];
  }
  public function getProfile() { return ['historyId' => '9999']; }
  public function listMessages($labelId, $pageToken = null, $q = null) {
    $this->calls[] = ['method' => 'LIST', 'label' => $labelId];
    $ids = $this->listByLabel[$labelId] ?? [];
    $msgs = [];
    foreach ($ids as $id) { $msgs[] = ['id' => $id]; }
    return ['messages' => $msgs];
  }
  public function getMessage($id, $format = 'full') {
    $this->calls[] = ['method' => 'GET', 'id' => $id, 'format' => $format];
    if (!isset($this->meta[$id])) {
      throw new GmailApiException('Not Found', 404);
    }
    $m = $this->meta[$id];
    if ($format === 'metadata') {
      $headers = [];
      foreach (($m['headers'] ?? []) as $n => $v) { $headers[] = ['name' => $n, 'value' => $v]; }
      return ['id' => $id, 'labelIds' => $m['labelIds'], 'internalDate' => $m['internalDate'], 'snippet' => $m['snippet'], 'payload' => ['headers' => $headers]];
    }
    return ['id' => $id, 'labelIds' => $m['labelIds'], 'internalDate' => $m['internalDate'], 'raw' => base64_encode(str_replace(['+', '/'], ['-', '_'], ''))];
  }
  public function getMessagesMetadataBatch($ids) {
    $out = [];
    foreach ($ids as $id) {
      $this->calls[] = ['method' => 'BATCH', 'id' => $id];
      if (!isset($this->meta[$id])) { $out[$id] = null; continue; }
      $m = $this->meta[$id];
      $headers = [];
      foreach (($m['headers'] ?? []) as $n => $v) { $headers[] = ['name' => $n, 'value' => $v]; }
      $out[$id] = ['labelIds' => $m['labelIds'], 'internalDate' => $m['internalDate'], 'snippet' => $m['snippet'], 'headers' => $headers];
    }
    return $out;
  }
  public function trashMessage($id) { $this->calls[] = ['method' => 'TRASH', 'id' => $id]; return []; }
  public function deleteMessage($id) { $this->calls[] = ['method' => 'DELETE', 'id' => $id]; return []; }
  public function modifyMessage($id, $add, $remove) { $this->calls[] = ['method' => 'MODIFY', 'id' => $id, 'add' => $add, 'remove' => $remove]; return []; }
}
class TestBackendDelete extends BackendGmail {
  public $stub;
  public function __construct($stub) { $this->stub = $stub; }
  protected function ensureApi() { return $this->stub; }
}
function readAsbodyDataDelete($data) {
  if (is_resource($data)) {
    rewind($data);
    return stream_get_contents($data);
  }
  if (is_object($data) && method_exists($data, '__toString')) {
    return (string)$data;
  }
  return (string)$data;
}
function metaEntry($labels, $date, $snippet, $subject) {
  return ['labelIds' => $labels, 'internalDate' => $date, 'snippet' => $snippet, 'headers' => ['Subject' => $subject, 'From' => 'a@example.com', 'To' => 'YOUR_GMAIL_ADDRESS', 'Date' => 'Mon, 27 Oct 2026 10:00:00 +0000']];
}
runTest('testDeleteFromTrashCallsPermanentDeleteNotNoop', function() {
  $stub = new StubApiDelete(['t1' => metaEntry(['TRASH'], '1761560000000', 'trash one', 'Trash One')], ['TRASH' => ['t1']]);
  $b = new TestBackendDelete($stub);
  $cp = new ContentParameters();
  $ok = $b->DeleteMessage('TRASH', 't1', $cp);
  assertTrue($ok === true, "Delete from Trash must return true");
  $deletes = array_values(array_filter($stub->calls, function($c) { return $c['method'] === 'DELETE'; }));
  assertEquals(count($deletes), 1, "Delete from Trash must call DELETE exactly once (old no-op made zero calls)");
  assertEquals($deletes[0]['id'], 't1', "DELETE must target the trashed message id");
});
runTest('testDeleteFromInboxStillTrashesNotDeletes', function() {
  $stub = new StubApiDelete(['m1' => metaEntry(['INBOX', 'UNREAD'], '1761560000000', 'inbox one', 'Inbox One')], ['INBOX' => ['m1']]);
  $b = new TestBackendDelete($stub);
  $cp = new ContentParameters();
  $ok = $b->DeleteMessage('INBOX', 'm1', $cp);
  assertTrue($ok === true, "Delete from Inbox must return true");
  $deletes = array_values(array_filter($stub->calls, function($c) { return $c['method'] === 'DELETE'; }));
  assertEquals(count($deletes), 0, "Delete from Inbox must NEVER call permanent DELETE");
  $trashes = array_values(array_filter($stub->calls, function($c) { return $c['method'] === 'TRASH'; }));
  assertEquals(count($trashes), 1, "Delete from Inbox must trash exactly once");
});
runTest('testEmptyFolderTrashDeletesEachMail', function() {
  $stub = new StubApiDelete(
    ['t1' => metaEntry(['TRASH'], '1761560000000', 'one', 'One'), 't2' => metaEntry(['TRASH'], '1761560001000', 'two', 'Two')],
    ['TRASH' => ['t1', 't2']]
  );
  $b = new TestBackendDelete($stub);
  $ok = $b->EmptyFolder('TRASH');
  assertTrue($ok === true, "Empty Trash must return true");
  $deletes = array_values(array_filter($stub->calls, function($c) { return $c['method'] === 'DELETE'; }));
  assertEquals(count($deletes), 2, "Empty Trash must DELETE each trashed message");
  $ids = array_column($deletes, 'id');
  sort($ids);
  assertEquals($ids, ['t1', 't2'], "Empty Trash DELETE ids must match Trash list");
});
runTest('testEmptyFolderInboxIsRejectedWithNoDeletes', function() {
  $stub = new StubApiDelete(['m1' => metaEntry(['INBOX'], '1761560000000', 'one', 'One')], ['INBOX' => ['m1']]);
  $b = new TestBackendDelete($stub);
  try {
    $b->EmptyFolder('INBOX');
    throw new Exception("Expected StatusException for Empty Inbox, got success/false");
  } catch (StatusException $e) {
    // Honest failure (framework turns this into an Empty error status),
    // never a fake success that would make mail reappear on next Sync.
    assertTrue($e->getCode() !== 0, "Empty Inbox rejection must carry a status code");
  }
  $deletes = array_values(array_filter($stub->calls, function($c) { return $c['method'] === 'DELETE'; }));
  assertEquals(count($deletes), 0, "Empty Inbox must NEVER call permanent DELETE");
});
runTest('testGetMessageListPopulatesCacheForSnippetPath', function() {
  $stub = new StubApiDelete(['s1' => metaEntry(['INBOX', 'UNREAD'], '1761560000000', 'snippet body here', 'Snippet Subject')], ['INBOX' => ['s1']]);
  $b = new TestBackendDelete($stub);
  $list = $b->GetMessageList('INBOX', 0);
  assertTrue(is_array($list) && count($list) === 1, "INBOX list must contain the one stub message");
  assertEquals($list[0]['id'], 's1', "List id must be s1");
  // Now GetMessage with an explicit preview request must use the cached snippet,
  // not fetch full raw. NOTE: asbody->preview is only set for protocol >= 14
  // (as in IMAP), and CLI tests run with no protocol version, so assert the
  // protocol-independent markers instead: truncated=1, Data == snippet,
  // subject from cached headers.
  $cp = new ContentParameters();
  $cp->BodyPreference(SYNC_BODYPREFERENCE_MIME)->SetPreview(100);
  $msg = $b->GetMessage('INBOX', 's1', $cp);
  assertTrue(isset($msg->asbody) && $msg->asbody->truncated == 1, "Snippet path must mark body truncated=1");
  $bodyData = readAsbodyDataDelete($msg->asbody->data);
  assertEquals($bodyData, 'snippet body here', "Snippet path Data must be the cached snippet, not full MIME");
  assertEquals($msg->subject, 'Snippet Subject', "Snippet path subject must come from cached headers");
});
echo "backend delete tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
