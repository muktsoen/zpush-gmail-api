<?php
// BackendGmail ChangesSink tests (Task 7) - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets/.gmail-oauth.json:/var/lib/z-push/.gmail-oauth.json -v /opt/zpush-gmail/secrets/.gmail-client.json:/var/lib/z-push/.gmail-client.json zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_ping_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. No message is permanently deleted (cleanup via trash only).
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
  $lines[] = 'Task 7 test body';
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
runTest('testChangesSinkInitialize', function() {
  $b = newBackend();
  assertTrue($b->HasChangesSink() === true, "HasChangesSink must be true");
  assertTrue($b->ChangesSinkInitialize('INBOX') === true, "Initialize INBOX must return true");
  assertTrue($b->ChangesSinkInitialize('ALLMAIL') === true, "Initialize ALLMAIL must return true");
  assertTrue($b->ChangesSinkInitialize('NOPE_UNKNOWN_FOLDER_XYZ') === false, "Initialize unknown folder must return false (not throw)");
  $b->Logoff();
});
runTest('testPingNoChangeTimeout', function() {
  $b = newBackend();
  assertTrue($b->ChangesSinkInitialize('INBOX') === true, "Initialize INBOX must return true");
  $state0 = [];
  $base0 = $b->GetChanges('INBOX', $state0);
  assertEquals(count($base0), 0, "No-change baseline GetChanges must return empty");
  $t0 = microtime(true);
  $res = $b->ChangesSink(5);
  $elapsed = microtime(true) - $t0;
  assertTrue(is_array($res), "ChangesSink must return array");
  assertEquals(count($res), 0, "No-change Sink must return empty array");
  assertTrue($elapsed >= 4.5, "No-change Sink must block full timeout (elapsed $elapsed < 4.5)");
  assertTrue($elapsed <= 10, "No-change Sink must not overshoot timeout badly (elapsed $elapsed > 10)");
  $b->Logoff();
});
runTest('testPingWakeByPolling', function() {
  $api = newApiClient();
  $backend = newBackend();
  assertTrue($backend->ChangesSinkInitialize('INBOX') === true, "Initialize INBOX must return true");
  $state = [];
  $base = $backend->GetChanges('INBOX', $state);
  assertEquals(count($base), 0, "GetChanges baseline must return empty");
  assertTrue(isset($state['lastHistoryId']) && $state['lastHistoryId'] !== '' && $state['lastHistoryId'] !== null, "Baseline must set lastHistoryId");
  $subject = '[BackendGmail Test] Task7 Ping ' . gmdate('YmdHis');
  $sent = $api->sendMessage(buildSimpleMime($subject));
  $testId = $sent['id'] ?? '';
  assertTrue($testId !== '', "send must return id");
  try {
    $t0 = microtime(true);
    $res = $backend->ChangesSink(25);
    $elapsed = microtime(true) - $t0;
    assertTrue(is_array($res), "ChangesSink must return array");
    assertTrue(in_array('INBOX', $res, true), "Wake Sink must return watched INBOX folder");
    assertTrue($elapsed < 15, "Wake Sink must return early on change (elapsed $elapsed >= 15)");
    $after = $backend->GetChanges('INBOX', $state);
    $found = null;
    foreach ($after as $c) {
      if (($c['id'] ?? '') === $testId && ($c['type'] ?? '') === 'added') { $found = $c; break; }
    }
    assertTrue($found !== null, "Subsequent GetChanges from baseline must still return test message as added (Sink must not consume history)");
    $cutoff = time() - 3600;
    $inboxIds = array_column($backend->GetMessageList('INBOX', $cutoff), 'id');
    assertTrue(in_array($testId, $inboxIds), "Subsequent GetMessageList INBOX must still contain test message (Sink must not consume)");
    $backend->Logoff();
  } finally {
    try {
      $cur = $api->getMessage($testId, 'metadata');
      $labels = $cur['labelIds'] ?? [];
      if (!in_array('TRASH', $labels, true)) {
        $api->trashMessage($testId);
      }
    } catch (Throwable $e) {}
  }
});
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
