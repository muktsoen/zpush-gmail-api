<?php
// BackendGmail ChangesSink wake-flag tests (Task 8) - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user root -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets:/var/lib/z-push zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_pubsub_wake_test.php  (directory mount: single-file mount breaks token refresh rename)
// Exit 0 if all pass, 1 otherwise. No secrets printed. Wake/highwater files are temp files, never production.
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
define('GMAIL_LOGON_IMAP_PROBE', false);
define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH');
$wakeFile = tempnam(sys_get_temp_dir(), 'wake') . '.json';
$hwFile = tempnam(sys_get_temp_dir(), 'hw') . '.json';
define('GMAIL_WAKE_FLAG_FILE', $wakeFile);
define('GMAIL_HIGHWATER_FILE', $hwFile);
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) { echo "FAIL: setup - BackendGmail class not found\n"; exit(1); }
$failures = 0; $passed = 0;
function runTest($name, $fn) {
  global $failures, $passed;
  try { $fn(); $passed++; echo "PASS: $name\n"; }
  catch (Throwable $e) { $failures++; echo "FAIL: $name - " . $e->getMessage() . "\n"; }
}
function assertTrue($c, $m) { if (!$c) throw new Exception($m); }
function assertEquals($a, $b, $m) { if ($a !== $b) throw new Exception($m . " (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")"); }
function newBackend() {
  $b = new BackendGmail();
  $ok = $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  assertTrue($ok === true, "Logon must succeed with probe disabled");
  return $b;
}
runTest('testWakeFlagTriggersSinkQuickly', function() use ($wakeFile, $hwFile) {
  $b = newBackend();
  file_put_contents($hwFile, json_encode(['historyId' => '9999999000']));
  file_put_contents($wakeFile, json_encode(['historyId' => '9999999001', 'receivedAt' => gmdate('c')]));
  assertTrue($b->ChangesSinkInitialize('INBOX') === true, "Initialize INBOX must return true");
  $t0 = microtime(true);
  $res = $b->ChangesSink(10);
  $elapsed = microtime(true) - $t0;
  assertTrue(is_array($res) && count($res) > 0, "Wake-flag Sink must return watched folders");
  assertTrue($elapsed < 8, "Wake-flag Sink must return quickly, not wait for poll timeout (elapsed $elapsed)");
  $b->Logoff();
});
runTest('testStaleWakeFlagDoesNotTrigger', function() use ($wakeFile, $hwFile) {
  $b = newBackend();
  file_put_contents($hwFile, json_encode(['historyId' => '9999999000']));
  file_put_contents($wakeFile, json_encode(['historyId' => '9999998999', 'receivedAt' => gmdate('c')]));
  assertTrue($b->ChangesSinkInitialize('INBOX') === true, "Initialize INBOX must return true");
  $t0 = microtime(true);
  $res = $b->ChangesSink(5);
  $elapsed = microtime(true) - $t0;
  assertEquals(0, count($res), "Stale wake flag must not trigger Sink");
  assertTrue($elapsed >= 4.5, "Stale wake Sink must block full timeout (elapsed $elapsed)");
  $b->Logoff();
});
runTest('testMalformedWakeFlagIsIgnored', function() use ($wakeFile, $hwFile) {
  $b = newBackend();
  file_put_contents($hwFile, json_encode(['historyId' => '9999999000']));
  file_put_contents($wakeFile, '{not json');
  assertTrue($b->ChangesSinkInitialize('INBOX') === true, "Initialize INBOX must return true");
  $res = $b->ChangesSink(5);
  assertEquals(0, count($res), "Malformed wake flag must be ignored, Sink must timeout empty");
  $b->Logoff();
});
@unlink($wakeFile); @unlink($hwFile);
echo "pubsub_wake: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
