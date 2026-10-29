<?php
// BackendGmail Logon labels de-duplication tests - plain PHP, no PHPUnit.
// Run:
//   docker run --rm --user root -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets:/var/lib/z-push zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_logon_labels_test.php
// Exit 0 if all pass, 1 otherwise. Probe is stubbed; GetFolderList must reuse Logon probe, not re-call Gmail.
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
define('GMAIL_LOGON_IMAP_PROBE', false);
define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH');
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) { echo "FAIL: setup - BackendGmail class not found\n"; exit(1); }
class LabelTestBackend extends BackendGmail {
  public $probeLabelsResult = null;
  public $probeCalls = 0;
  protected function probeLabels() {
    $this->probeCalls++;
    return $this->probeLabelsResult;
  }
  public function getFolderCache() {
    $r = new ReflectionProperty('BackendGmail', 'folderCache');
    $r->setAccessible(true); return $r->getValue($this);
  }
}
function fakeLabels() {
  return ['labels' => [
    ['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'],
    ['id' => 'SENT', 'name' => 'SENT', 'type' => 'system'],
    ['id' => 'FAKE1', 'name' => 'Fake One', 'type' => 'user'],
    ['id' => 'STARRED', 'name' => 'STARRED', 'type' => 'system'],
    ['id' => 'CATEGORY_SOCIAL', 'name' => 'CATEGORY_SOCIAL', 'type' => 'system'],
  ]];
}
$failures = 0; $passed = 0;
function runTest($name, $fn) {
  global $failures, $passed;
  try { $fn(); $passed++; echo "PASS: $name\n"; }
  catch (Throwable $e) { $failures++; echo "FAIL: $name - " . $e->getMessage() . "\n"; }
}
function assertTrue($c, $m) { if (!$c) throw new Exception($m); }
runTest('testLogonPopulatesFolderCache', function() {
  $b = new LabelTestBackend();
  $b->probeLabelsResult = fakeLabels();
  $ok = $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  assertTrue($ok === true, "Logon with stub probe must succeed");
  $cache = $b->getFolderCache();
  assertTrue(is_array($cache), "Folder cache must be populated by Logon probe");
  assertTrue(isset($cache['FAKE1']), "Folder cache must contain fake user label from probe");
  assertTrue(!isset($cache['STARRED']) && !isset($cache['CATEGORY_SOCIAL']), "Skipped labels must be filtered from cache");
});
runTest('testGetFolderListReusesLogonProbe', function() {
  $b = new LabelTestBackend();
  $b->probeLabelsResult = fakeLabels();
  $b->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  $list = $b->GetFolderList();
  $ids = array_column($list, 'id');
  assertTrue(in_array('FAKE1', $ids), "GetFolderList must reuse Logon probe labels, got: " . implode(',', $ids));
  assertTrue(in_array('ALLMAIL', $ids), "GetFolderList must include ALLMAIL");
  assertTrue(!in_array('STARRED', $ids), "Skipped labels must not appear in folder list");
  assertTrue($b->probeCalls === 1, "Probe must be called exactly once per request (Logon only, not again in GetFolderList), got " . $b->probeCalls);
});
runTest('testLabelChangeRefreshesNextRequest', function() {
  $b1 = new LabelTestBackend();
  $b1->probeLabelsResult = fakeLabels();
  $b1->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  $ids1 = array_column($b1->GetFolderList(), 'id');
  assertTrue(in_array('FAKE1', $ids1), "First request must see FAKE1");
  $b2 = new LabelTestBackend();
  $labels2 = fakeLabels();
  $labels2['labels'] = array_filter($labels2['labels'], function($l) { return $l['id'] !== 'FAKE1'; });
  $labels2['labels'][] = ['id' => 'FAKE2', 'name' => 'Fake Two', 'type' => 'user'];
  $b2->probeLabelsResult = $labels2;
  $b2->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
  $ids2 = array_column($b2->GetFolderList(), 'id');
  assertTrue(!in_array('FAKE1', $ids2) && in_array('FAKE2', $ids2), "New request must see new labels, not stale cache, got: " . implode(',', $ids2));
});
echo "logon_labels: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
