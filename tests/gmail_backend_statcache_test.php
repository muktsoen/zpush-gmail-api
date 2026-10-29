<?php
// BackendGmail Stat cache tests - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user root -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets:/var/lib/z-push zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_statcache_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed, no real Gmail calls (fetch/profile stubbed via subclass).
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
define('GMAIL_LOGON_IMAP_PROBE', false);
define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH');
$cacheDir = sys_get_temp_dir() . '/statcache-test-' . getmypid();
@mkdir($cacheDir, 0700, true);
define('GMAIL_STAT_CACHE_DIR', $cacheDir);
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) { echo "FAIL: setup - BackendGmail class not found\n"; exit(1); }
class StatTestBackend extends BackendGmail {
  public $historyId = '1000';
  public $fetchCalls = 0;
  public $fetchList = [];
  public $fetchMeta = [];
  public function setTestUser($u) {
    $r = new ReflectionProperty('BackendGmail', 'username');
    $r->setAccessible(true); $r->setValue($this, $u);
  }
  public function getMetaCache() {
    $r = new ReflectionProperty('BackendGmail', 'metaCache');
    $r->setAccessible(true); return $r->getValue($this);
  }
  public function callCacheFile($folder, $cutoff) {
    $m = new ReflectionMethod('BackendGmail', 'statCacheFile');
    $m->setAccessible(true); return $m->invoke($this, $folder, $cutoff);
  }
  public function callAcquireLock($file) {
    $m = new ReflectionMethod('BackendGmail', 'acquireStatLock');
    $m->setAccessible(true); return $m->invoke($this, $file);
  }
  public function callReleaseLock($h) {
    $m = new ReflectionMethod('BackendGmail', 'releaseStatLock');
    $m->setAccessible(true); return $m->invoke($this, $h);
  }
  protected function getCurrentHistoryId() { return $this->historyId; }
  protected function fetchFolderStatList($folderid, $cutoffdate) {
    $this->fetchCalls++;
    $r = new ReflectionProperty('BackendGmail', 'metaCache');
    $r->setAccessible(true); $r->setValue($this, $this->fetchMeta);
    return $this->fetchList;
  }
}
function newBackend() {
  $b = new StatTestBackend();
  $b->setTestUser('YOUR_GMAIL_ADDRESS');
  $b->fetchList = [['id' => 'msg1', 'mod' => 'mod1', 'flags' => 0, 'answered' => 0, 'forwarded' => 0, 'star' => 0]];
  $b->fetchMeta = ['msg1' => ['labelIds' => ['INBOX'], 'internalDate' => '1700000000000', 'snippet' => 'hello', 'headers' => [['name' => 'Subject', 'value' => 'Hi']]]];
  return $b;
}
$failures = 0; $passed = 0;
function runTest($name, $fn) {
  global $failures, $passed;
  try { $fn(); $passed++; echo "PASS: $name\n"; }
  catch (Throwable $e) { $failures++; echo "FAIL: $name - " . $e->getMessage() . "\n"; }
}
function assertTrue($c, $m) { if (!$c) throw new Exception($m); }
function assertEquals($a, $b, $m) { if ($a !== $b) throw new Exception($m . " (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")"); }
runTest('testCacheHitSkipsFetch', function() {
  $b = newBackend();
  $l1 = $b->GetMessageList('INBOX', 0);
  $l2 = $b->GetMessageList('INBOX', 0);
  assertEquals($l1, $l2, "Second call must return same list");
  assertEquals(1, $b->fetchCalls, "Second call with same historyId must not refetch");
  $meta = $b->getMetaCache();
  assertTrue(isset($meta['msg1']) && $meta['msg1']['snippet'] === 'hello', "Cache hit must restore metaCache for preview path");
});
runTest('testHistoryChangeInvalidates', function() {
  $b = newBackend();
  $b->GetMessageList('SENT', 0);
  $b->historyId = '1001';
  $b->fetchList = [['id' => 'msg2', 'mod' => 'mod2', 'flags' => 0, 'answered' => 0, 'forwarded' => 0, 'star' => 0]];
  $l = $b->GetMessageList('SENT', 0);
  assertEquals(2, $b->fetchCalls, "History change must refetch");
  assertEquals('msg2', $l[0]['id'], "Refetched list must be new list");
});
runTest('testCutoffChangeInvalidates', function() {
  $b = newBackend();
  $b->GetMessageList('DRAFT', 0);
  $b->GetMessageList('DRAFT', 123456);
  assertEquals(2, $b->fetchCalls, "Cutoff change must use a different cache key and refetch");
});
runTest('testCorruptCacheFallsBackToFetch', function() {
  $b = newBackend();
  $b->GetMessageList('TRASH', 0);
  $f = $b->callCacheFile('TRASH', 0);
  file_put_contents($f, '{not json');
  $b->GetMessageList('TRASH', 0);
  assertEquals(2, $b->fetchCalls, "Corrupt cache must fall back to fetch, not return garbage");
});
runTest('testLockSingleFlight', function() {
  $b = newBackend();
  $f = $b->callCacheFile('SPAM', 0);
  $h1 = $b->callAcquireLock($f . '.lock');
  assertTrue($h1 !== false, "First lock acquire must succeed");
  $h2 = $b->callAcquireLock($f . '.lock');
  assertTrue($h2 === false, "Second lock acquire while held must fail fast (single-flight)");
  $b->callReleaseLock($h1);
  $h3 = $b->callAcquireLock($f . '.lock');
  assertTrue($h3 !== false, "Lock must be acquirable after release");
  $b->callReleaseLock($h3);
});
echo "statcache: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
