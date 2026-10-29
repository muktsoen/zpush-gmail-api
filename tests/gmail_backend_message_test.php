<?php
// BackendGmail message tests (Task 4) - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets/.gmail-oauth.json:/var/lib/z-push/.gmail-oauth.json -v /opt/zpush-gmail/secrets/.gmail-client.json:/var/lib/z-push/.gmail-client.json zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_message_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. Test messages are sent via API and trashed in try/finally (never permanently deleted).
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
function buildTestMime($subject) {
  $boundary = 'task4testboundary123';
  $lines = [];
  $lines[] = 'From: YOUR_GMAIL_ADDRESS';
  $lines[] = 'To: YOUR_GMAIL_ADDRESS';
  $lines[] = 'Subject: ' . $subject;
  $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
  $lines[] = 'MIME-Version: 1.0';
  $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
  $lines[] = '';
  $lines[] = '--' . $boundary;
  $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
  $lines[] = '';
  $lines[] = 'Task 4 test body';
  $lines[] = '';
  $lines[] = '--' . $boundary;
  $lines[] = 'Content-Type: text/plain; name="hello.txt"';
  $lines[] = 'Content-Disposition: attachment; filename="hello.txt"';
  $lines[] = 'Content-Transfer-Encoding: 7bit';
  $lines[] = '';
  $lines[] = 'Task 4 attachment content';
  $lines[] = '';
  $lines[] = '--' . $boundary . '--';
  $lines[] = '';
  return implode("\r\n", $lines);
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
runTest('testMessageListAndFetch', function() {
  $api = newApiClient();
  $subject = '[BackendGmail Test] Task4 ' . gmdate('YmdHis');
  $sent = $api->sendMessage(buildTestMime($subject));
  $sentId = $sent['id'] ?? '';
  assertTrue($sentId !== '', "sendMessage must return an id");
  try {
    $backend = newBackend();
    $cutoff = time() - 3600;
    $list = $backend->GetMessageList('INBOX', $cutoff);
    assertTrue(is_array($list) && count($list) >= 1, "INBOX list (last hour) must contain >=1 message");
    $ids = array_column($list, 'id');
    assertTrue(in_array($sentId, $ids), "INBOX list must contain just-sent test message");
    $stat1 = $backend->StatMessage('INBOX', $sentId);
    $stat2 = $backend->StatMessage('INBOX', $sentId);
    assertTrue(is_array($stat1) && is_array($stat2), "StatMessage must return arrays");
    assertEquals($stat1['mod'], $stat2['mod'], "StatMessage mod must be stable across two calls");
    assertEquals($stat1['id'], $sentId, "StatMessage id");
    assertEquals($stat1['flags'], 0, "Just-sent message must be unread (flags 0)");
    $cp = new ContentParameters();
    $msg = $backend->GetMessage('INBOX', $sentId, $cp);
    assertTrue($msg instanceof SyncMail, "GetMessage must return SyncMail object");
    assertEquals($msg->subject, $subject, "GetMessage subject must match sent subject");
    assertTrue(strpos((string)$msg->from, 'YOUR_GMAIL_ADDRESS') !== false, "GetMessage from must contain self address, got: " . (string)$msg->from);
    assertTrue(is_int($msg->datereceived) || is_numeric($msg->datereceived), "GetMessage datereceived must be numeric");
    assertTrue(abs((int)$msg->datereceived - time()) < 3600, "GetMessage datereceived must be within 1 hour of now");
    assertEquals($msg->read, 0, "GetMessage read must be 0 for unread test message");
    assertTrue(isset($msg->asbody) && $msg->asbody->type == SYNC_BODYPREFERENCE_MIME, "GetMessage asbody must be MIME type");
    $mime = readAsbodyData($msg->asbody->data);
    assertTrue(strpos($mime, 'Task 4 test body') !== false, "MIME must contain body text");
    assertTrue(strpos($mime, 'Task 4 attachment content') !== false, "MIME must contain attachment content");
    assertTrue(strpos($mime, 'hello.txt') !== false, "MIME must contain attachment filename");
    $futureList = $backend->GetMessageList('INBOX', time() + 3600);
    $futureIds = array_column($futureList, 'id');
    assertTrue(!in_array($sentId, $futureIds), "Future cutoff list must NOT contain just-sent message");
    $backend->Logoff();
  } finally {
    try { $api->trashMessage($sentId); } catch (Throwable $e) { echo "  (cleanup trash note: " . $e->getMessage() . ")\n"; }
  }
});
runTest('testAllMailMapping', function() {
  $api = newApiClient();
  $subjectA = '[BackendGmail Test] Task4 AllMail A ' . gmdate('YmdHis');
  $subjectB = '[BackendGmail Test] Task4 AllMail B ' . gmdate('YmdHis');
  $sentA = $api->sendMessage(buildTestMime($subjectA));
  $sentB = $api->sendMessage(buildTestMime($subjectB));
  $idA = $sentA['id'] ?? '';
  $idB = $sentB['id'] ?? '';
  assertTrue($idA !== '' && $idB !== '', "Both test sends must return ids");
  $api->trashMessage($idB);
  try {
    $backend = newBackend();
    $cutoff = time() - 3600;
    $allList = $backend->GetMessageList('ALLMAIL', $cutoff);
    $allIds = array_column($allList, 'id');
    assertTrue(in_array($idA, $allIds), "All Mail must contain INBOX test message A");
    assertTrue(!in_array($idB, $allIds), "All Mail must NOT contain trashed test message B");
    $trashList = $backend->GetMessageList('TRASH', $cutoff);
    $trashIds = array_column($trashList, 'id');
    assertTrue(in_array($idB, $trashIds), "Trash list must contain trashed test message B (proves exclusion is meaningful)");
    $inboxList = $backend->GetMessageList('INBOX', $cutoff);
    $inboxIds = array_column($inboxList, 'id');
    assertTrue(in_array($idA, $inboxIds), "INBOX list must contain test message A (proves inclusion is meaningful)");
    $backend->Logoff();
  } finally {
    try { $api->trashMessage($idA); } catch (Throwable $e) { echo "  (cleanup trash note: " . $e->getMessage() . ")\n"; }
  }
});
runTest('testMessageListUnknownFolderThrows', function() {
  $backend = newBackend();
  try {
    $backend->GetMessageList('NONEXISTENT_LABEL_XYZ', 0);
    throw new Exception("Expected StatusException for unknown folder");
  } catch (StatusException $e) {
    assertTrue(true, "StatusException thrown as expected");
  }
  $backend->Logoff();
});
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
