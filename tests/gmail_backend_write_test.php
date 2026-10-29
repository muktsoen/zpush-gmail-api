<?php
// BackendGmail write-op tests (Task 6 core) - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets/.gmail-oauth.json:/var/lib/z-push/.gmail-oauth.json -v /opt/zpush-gmail/secrets/.gmail-client.json:/var/lib/z-push/.gmail-client.json zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_write_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. Trash delete now ATTEMPTS permanent delete (was a no-op). With the current gmail.modify scope that attempt fails 403 (scope blocker, not a code bug); after a full-scope re-authorization it must succeed and the message must disappear. Non-Trash deletes still only trash.
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
  $lines[] = 'Task 6 test body';
  $lines[] = '';
  return implode("\r\n", $lines);
}
function buildSendMime($subject) {
  $bOuter = 'task6outer456';
  $bAlt = 'task6alt789';
  $attB64 = base64_encode('Task 6 attachment content');
  $lines = [];
  $lines[] = 'From: YOUR_GMAIL_ADDRESS';
  $lines[] = 'To: YOUR_GMAIL_ADDRESS';
  $lines[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
  $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
  $lines[] = 'MIME-Version: 1.0';
  $lines[] = 'Content-Type: multipart/mixed; boundary="' . $bOuter . '"';
  $lines[] = '';
  $lines[] = '--' . $bOuter;
  $lines[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
  $lines[] = '';
  $lines[] = '--' . $bAlt;
  $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
  $lines[] = '';
  $lines[] = 'Task 6 plain body';
  $lines[] = '';
  $lines[] = '--' . $bAlt;
  $lines[] = 'Content-Type: text/html; charset="UTF-8"';
  $lines[] = '';
  $lines[] = '<p>Task 6 HTML body</p>';
  $lines[] = '';
  $lines[] = '--' . $bAlt . '--';
  $lines[] = '';
  $lines[] = '--' . $bOuter;
  $lines[] = 'Content-Type: text/plain; name="hello6.txt"';
  $lines[] = 'Content-Disposition: attachment; filename="hello6.txt"';
  $lines[] = 'Content-Transfer-Encoding: base64';
  $lines[] = '';
  $lines[] = $attB64;
  $lines[] = '';
  $lines[] = '--' . $bOuter . '--';
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
function findImapTrashLabelId($api) {
  $labels = $api->listLabels();
  foreach (($labels['labels'] ?? []) as $l) {
    if (($l['name'] ?? '') === '[Imap]/Trash') {
      return $l['id'];
    }
  }
  throw new Exception("[Imap]/Trash label not found");
}
function apiLabelIds($api, $id) {
  $m = $api->getMessage($id, 'metadata');
  return $m['labelIds'] ?? [];
}
runTest('testSendMimeFidelity', function() {
  $api = newApiClient();
  $backend = newBackend();
  $subject = '[BackendGmail Test] Task6 发送 中文 ' . gmdate('YmdHis');
  $sm = new SyncSendMail();
  $sm->mime = buildSendMime($subject);
  $sm->saveinsent = true;
  $sentId = null;
  try {
    $ok = $backend->SendMail($sm);
    assertTrue($ok === true, "SendMail must return true");
    $cutoff = time() - 3600;
    $sentList = $backend->GetMessageList('SENT', $cutoff);
    $foundId = null;
    $foundMsg = null;
    $cp = new ContentParameters();
    foreach ($sentList as $entry) {
      $mid = $entry['id'];
      try {
        $msg = $backend->GetMessage('SENT', $mid, $cp);
      } catch (Throwable $e) {
        continue;
      }
      if ($msg->subject === $subject) {
        $foundId = $mid;
        $foundMsg = $msg;
        break;
      }
    }
    assertTrue($foundId !== null, "Sent message with test subject must be found in SENT list");
    $sentId = $foundId;
    $labels = apiLabelIds($api, $foundId);
    assertTrue(in_array('SENT', $labels, true), "Sent message labelIds must contain SENT");
    $mime = readAsbodyData($foundMsg->asbody->data);
    assertTrue(strpos($mime, 'Task 6 plain body') !== false, "Sent MIME must contain plain body");
    assertTrue(strpos($mime, 'Task 6 HTML body') !== false, "Sent MIME must contain HTML body");
    assertTrue(strpos($mime, 'hello6.txt') !== false, "Sent MIME must contain attachment filename");
    assertTrue(strpos($mime, base64_encode('Task 6 attachment content')) !== false, "Sent MIME must contain base64 attachment content");
    $backend->Logoff();
  } finally {
    if ($sentId !== null) {
      try { $api->trashMessage($sentId); } catch (Throwable $e) { echo "  (cleanup trash note: " . $e->getMessage() . ")\n"; }
    }
  }
});
runTest('testWriteOpsRoundTrip', function() {
  $api = newApiClient();
  $labelX = findImapTrashLabelId($api);
  $subject = '[BackendGmail Test] Task6 WriteOps ' . gmdate('YmdHis');
  $sent = $api->sendMessage(buildSimpleMime($subject));
  $testId = $sent['id'] ?? '';
  assertTrue($testId !== '', "send must return id");
  $backend = newBackend();
  $cp = new ContentParameters();
  try {
    $labels0 = apiLabelIds($api, $testId);
    assertTrue(in_array('UNREAD', $labels0, true), "New test message must start unread");
    $ok = $backend->SetReadFlag('INBOX', $testId, 1, $cp);
    assertTrue($ok === true, "SetReadFlag read must return true");
    $labels1 = apiLabelIds($api, $testId);
    assertTrue(!in_array('UNREAD', $labels1, true), "After mark read, UNREAD must be gone");
    $stat1 = $backend->StatMessage('INBOX', $testId);
    assertEquals($stat1['flags'], 1, "StatMessage flags must be 1 after read");
    $ok = $backend->SetReadFlag('INBOX', $testId, 0, $cp);
    assertTrue($ok === true, "SetReadFlag unread must return true");
    $labels2 = apiLabelIds($api, $testId);
    assertTrue(in_array('UNREAD', $labels2, true), "After mark unread, UNREAD must be present");
    $ok = $backend->SetReadFlag('INBOX', $testId, 1, $cp);
    assertTrue($ok === true, "SetReadFlag read again must return true");
    $newId = $backend->MoveMessage('INBOX', $testId, $labelX, $cp);
    assertTrue($newId === $testId, "MoveMessage must return same id (Gmail id stable across labels)");
    $labels3 = apiLabelIds($api, $testId);
    assertTrue(in_array($labelX, $labels3, true), "After move, labelIds must contain destination label");
    assertTrue(!in_array('INBOX', $labels3, true), "After move, labelIds must NOT contain source INBOX");
    $cutoff = time() - 3600;
    $inboxIds = array_column($backend->GetMessageList('INBOX', $cutoff), 'id');
    assertTrue(!in_array($testId, $inboxIds), "After move, INBOX list must NOT contain test message");
    $labelIds = array_column($backend->GetMessageList($labelX, $cutoff), 'id');
    assertTrue(in_array($testId, $labelIds), "After move, destination list must contain test message");
    $ok = $backend->DeleteMessage($labelX, $testId, $cp);
    assertTrue($ok === true, "DeleteMessage must return true");
    $labels4 = apiLabelIds($api, $testId);
    assertTrue(in_array('TRASH', $labels4, true), "After delete, labelIds must contain TRASH (never permanently deleted)");
    assertTrue(!in_array($labelX, $labels4, true), "After delete, labelIds must NOT contain source label");
    $labelIdsAfter = array_column($backend->GetMessageList($labelX, $cutoff), 'id');
    assertTrue(!in_array($testId, $labelIdsAfter), "After delete, destination list must NOT contain test message");
    $trashIds = array_column($backend->GetMessageList('TRASH', $cutoff), 'id');
    assertTrue(in_array($testId, $trashIds), "After delete, TRASH list must contain test message");
    // Trash delete now attempts permanent delete (old code was a no-op that
    // made deleted mail reappear on resync). With the current gmail.modify
    // scope Google rejects permanent delete with 403: that honest failure
    // proves the code attempts delete (not a no-op). After a full-scope
    // re-authorization this block must be updated to expect success and
    // disappearance (404 on re-fetch).
    try {
      $backend->DeleteMessage('TRASH', $testId, $cp);
      throw new Exception("Expected 403 for Trash permanent delete with gmail.modify scope, got success (scope may have been upgraded - update this test to expect success)");
    } catch (GmailApiException $e) {
      assertEquals($e->httpStatus, 403, "Trash permanent delete with gmail.modify scope must fail 403 (scope blocker)");
    }
    $labels5 = apiLabelIds($api, $testId);
    assertTrue(in_array('TRASH', $labels5, true), "After failed (403) Trash delete, message must still be in TRASH (delete did not happen)");
    $backend->Logoff();
  } finally {
    // Test message already in TRASH at end (no further cleanup needed); if test failed early, trash it.
    try {
      $cur = apiLabelIds($api, $testId);
      if (!in_array('TRASH', $cur, true)) {
        $api->trashMessage($testId);
      }
    } catch (Throwable $e) {}
  }
});
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
