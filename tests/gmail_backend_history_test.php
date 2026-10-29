<?php
// BackendGmail history tests (Task 5) - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets/.gmail-oauth.json:/var/lib/z-push/.gmail-oauth.json -v /opt/zpush-gmail/secrets/.gmail-client.json:/var/lib/z-push/.gmail-client.json zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_history_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. Test messages are sent via API and trashed (never permanently deleted). LabelX = [Imap]/Trash (only user label, looked up by name).
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
function assertEquals($a, $b, $msg) {
  if ($a !== $b) throw new Exception($msg . " (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")");
}
function buildSimpleMime($subject) {
  $lines = [];
  $lines[] = 'From: YOUR_GMAIL_ADDRESS';
  $lines[] = 'To: YOUR_GMAIL_ADDRESS';
  $lines[] = 'Subject: ' . $subject;
  $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
  $lines[] = 'MIME-Version: 1.0';
  $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
  $lines[] = '';
  $lines[] = 'Task 5 test body';
  $lines[] = '';
  return implode("\r\n", $lines);
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
function findImapTrashLabelId($api) {
  $labels = $api->listLabels();
  $list = $labels['labels'] ?? [];
  foreach ($list as $l) {
    if (($l['name'] ?? '') === '[Imap]/Trash') {
      return $l['id'];
    }
  }
  throw new Exception("[Imap]/Trash label not found");
}
function findChange($changes, $id, $type) {
  foreach ($changes as $c) {
    if (($c['id'] ?? '') === $id && ($c['type'] ?? '') === $type) {
      return $c;
    }
  }
  return null;
}
runTest('testMultiLabelReadSync', function() {
  $api = newApiClient();
  $labelX = findImapTrashLabelId($api);
  $subject = '[BackendGmail Test] Task5 Multi ' . gmdate('YmdHis');
  $sent = $api->sendMessage(buildSimpleMime($subject));
  $testId = $sent['id'] ?? '';
  assertTrue($testId !== '', "send must return id");
  $api->modifyMessage($testId, [$labelX], []);
  try {
    $backend = newBackend();
    $stateInbox = [];
    $backend->GetChanges('INBOX', $stateInbox);
    assertTrue(!empty($stateInbox['lastHistoryId']), "INBOX baseline must set lastHistoryId");
    $stateLabelX = [];
    $backend->GetChanges($labelX, $stateLabelX);
    assertTrue(!empty($stateLabelX['lastHistoryId']), "LabelX baseline must set lastHistoryId");
    $api->modifyMessage($testId, [], ['UNREAD']);
    $changesLabelX = $backend->GetChanges($labelX, $stateLabelX);
    $chX = findChange($changesLabelX, $testId, 'changed');
    assertTrue($chX !== null, "LabelX GetChanges must return test message as changed after read");
    $stat = $backend->StatMessage($labelX, $testId);
    assertTrue(is_array($stat), "StatMessage must return array");
    assertEquals($chX['mod'] ?? '', $stat['mod'] ?? '', "GetChanges mod must match StatMessage mod");
    $changesInbox = $backend->GetChanges('INBOX', $stateInbox);
    $chI = findChange($changesInbox, $testId, 'changed');
    assertTrue($chI !== null, "INBOX GetChanges must also return test message as changed after read");
    $backend->Logoff();
  } finally {
    try { $api->modifyMessage($testId, [], [$labelX]); } catch (Throwable $e) {}
    try { $api->trashMessage($testId); } catch (Throwable $e) { echo "  (cleanup trash note: " . $e->getMessage() . ")\n"; }
  }
});
runTest('testHistoryInvalidFallback', function() {
  $api = newApiClient();
  $labelX = findImapTrashLabelId($api);
  $subject = '[BackendGmail Test] Task5 Fallback ' . gmdate('YmdHis');
  $sent = $api->sendMessage(buildSimpleMime($subject));
  $testId = $sent['id'] ?? '';
  assertTrue($testId !== '', "send must return id");
  $api->modifyMessage($testId, [$labelX], []);
  try {
    $backend = newBackend();
    $realList = $backend->GetMessageList($labelX, 0);
    $realIds = array_column($realList, 'id');
    assertTrue(in_array($testId, $realIds), "Real [Imap]/Trash list must contain test message");
    $stateInvalid = ['lastHistoryId' => '1'];
    $fallback = $backend->GetChanges($labelX, $stateInvalid);
    $fallbackIds = array_column($fallback, 'id');
    sort($fallbackIds);
    $sortedReal = $realIds;
    sort($sortedReal);
    assertEquals($fallbackIds, $sortedReal, "Fallback changes ids must equal real folder content ids");
    assertTrue(!empty($stateInvalid['lastHistoryId']) && $stateInvalid['lastHistoryId'] !== '1', "Fallback must reset lastHistoryId to current");
    $rerun = $backend->GetChanges($labelX, $stateInvalid);
    $rerunIds = array_column($rerun, 'id');
    $overlap = array_intersect($rerunIds, $fallbackIds);
    assertTrue(count($overlap) === 0, "Immediate re-run must not repeat fallback ids (idempotent), overlap: " . implode(',', $overlap));
    $backend->Logoff();
  } finally {
    try { $api->modifyMessage($testId, [], [$labelX]); } catch (Throwable $e) {}
    try { $api->trashMessage($testId); } catch (Throwable $e) { echo "  (cleanup trash note: " . $e->getMessage() . ")\n"; }
  }
});
runTest('testIncrementalAddDelete', function() {
  $api = newApiClient();
  $backend = newBackend();
  $stateInbox = [];
  $backend->GetChanges('INBOX', $stateInbox);
  $stateTrash = [];
  $backend->GetChanges('TRASH', $stateTrash);
  $subject = '[BackendGmail Test] Task5 Incr ' . gmdate('YmdHis');
  $sent = $api->sendMessage(buildSimpleMime($subject));
  $testId = $sent['id'] ?? '';
  assertTrue($testId !== '', "send must return id");
  $changesAdd = $backend->GetChanges('INBOX', $stateInbox);
  assertTrue(findChange($changesAdd, $testId, 'added') !== null, "INBOX GetChanges must return sent message as added");
  $api->trashMessage($testId);
  $changesDel = $backend->GetChanges('INBOX', $stateInbox);
  assertTrue(findChange($changesDel, $testId, 'deleted') !== null, "INBOX GetChanges must return trashed message as deleted");
  $changesTrash = $backend->GetChanges('TRASH', $stateTrash);
  assertTrue(findChange($changesTrash, $testId, 'added') !== null, "TRASH GetChanges must return trashed message as added");
  $backend->Logoff();
});
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
