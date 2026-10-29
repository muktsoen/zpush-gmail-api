<?php
// BackendGmail Logon quota test - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user root -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets:/var/lib/z-push zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_logon_quota_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed. Probe is stubbed via subclass, no real Gmail calls in quota cases.
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
define('GMAIL_LOGON_IMAP_PROBE', false);
define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH');
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) { echo "FAIL: setup - BackendGmail class not found\n"; exit(1); }
class TestBackendGmail extends BackendGmail {
  public $probeException = null;
  public $probeCalled = false;
  protected function probeLabels() {
    $this->probeCalled = true;
    if ($this->probeException !== null) throw $this->probeException;
    return ['labels' => [['id' => 'INBOX']]];
  }
}
$failures = 0; $passed = 0;
function runTest($name, $fn) {
  global $failures, $passed;
  try { $fn(); $passed++; echo "PASS: $name\n"; }
  catch (Throwable $e) { $failures++; echo "FAIL: $name - " . $e->getMessage() . "\n"; }
}
function assertTrue($c, $m) { if (!$c) throw new Exception($m); }
// 1. Quota 403 in probe must throw 503, not return false (false = password prompt in iOS).
runTest('testLogonQuotaThrows503', function() {
  $b = new TestBackendGmail();
  $b->probeException = new GmailApiException("Gmail API error 403: {\"error\":{\"message\":\"Quota exceeded for quota metric 'Total Query Cost'\"}}", 403);
  try {
    $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
    throw new Exception("Logon with quota must throw, not return");
  } catch (ServiceUnavailableException $e) {
    assertTrue(true, "503 thrown");
  }
});
// 2. 429 must also throw 503.
runTest('testLogonRateLimitThrows503', function() {
  $b = new TestBackendGmail();
  $b->probeException = new GmailApiException('Gmail API 限流 (429)', 429);
  try {
    $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
    throw new Exception("Logon with 429 must throw, not return");
  } catch (ServiceUnavailableException $e) {
    assertTrue(true, "503 thrown");
  }
});
// 3. Real auth failure (401) must still return false, not 503.
runTest('testLogonAuthFailureStillFalse', function() {
  $b = new TestBackendGmail();
  $b->probeException = new GmailApiException('Gmail API 认证失败', 401);
  $ok = $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  assertTrue($ok === false, "Logon with 401 must return false");
});
// 4. Wrong device password must return false without calling the Gmail probe (saves quota).
runTest('testWrongPasswordSkipsProbe', function() {
  $b = new TestBackendGmail();
  $ok = $b->Logon('YOUR_GMAIL_ADDRESS', '', 'wrong-password-for-test-12345');
  assertTrue($ok === false, "Wrong password must return false");
  assertTrue($b->probeCalled === false, "Wrong password must not call Gmail probe");
});
echo "logon_quota: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
