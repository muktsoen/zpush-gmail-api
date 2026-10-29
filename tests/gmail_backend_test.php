<?php
// BackendGmail folder tests - plain PHP, no PHPUnit.
// Run probe-disabled (folder tests):
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail -v /opt/zpush-gmail/secrets/.gmail-oauth.json:/var/lib/z-push/.gmail-oauth.json -v /opt/zpush-gmail/secrets/.gmail-client.json:/var/lib/z-push/.gmail-client.json zpush-zpush:latest php /opt/zpush-gmail/tests/gmail_backend_test.php
// Run probe-enabled (bad-password test only):
//   ... -e GMAIL_TEST_PROBE=1 ... php /opt/zpush-gmail/tests/gmail_backend_test.php
// Exit 0 if all pass, 1 otherwise. No secrets printed.
chdir('/opt/zpush-gmail/app');
require_once '/opt/zpush-gmail/app/vendor/autoload.php';
require_once '/opt/zpush-gmail/app/config.php';
try { ZPush::CheckConfig(); ZLog::Initialize(); } catch (Throwable $e) { echo 'FAIL: setup - bootstrap failed' . PHP_EOL; exit(1); }
$probeEnabled = (getenv('GMAIL_TEST_PROBE') === '1');
if ($probeEnabled) {
  define('GMAIL_LOGON_IMAP_PROBE', true);
} else {
  define('GMAIL_LOGON_IMAP_PROBE', false);
}
define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH'); // Test-only hash for dummy-probe-disabled; production hash in config.php is untouched.
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/gmail.php';
if (!class_exists('BackendGmail')) {
  echo "FAIL: setup - BackendGmail class not found (gmail.php missing)\n";
  exit(1);
}
echo "Probe mode: " . ($probeEnabled ? "enabled" : "disabled") . "\n";
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
if ($probeEnabled) {
  runTest('testLogonBadPasswordWithProbe', function() {
    $backend = new BackendGmail();
    $ok = $backend->Logon('YOUR_GMAIL_ADDRESS', '', 'wrong-password-for-test-12345');
    assertTrue($ok === false, "Logon with wrong IMAP password must return false (probe)");
  });
} else {
  runTest('testFolderSyncDirect', function() {
    $backend = new BackendGmail();
    $ok = $backend->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
    assertTrue($ok === true, "Logon must succeed with probe disabled");
    $list = $backend->GetFolderList();
    assertTrue(is_array($list) && count($list) >= 7, "Expected >=7 folders (5 system + ALLMAIL + 1 user), got " . count($list));
    $ids = array_column($list, 'id');
    assertTrue(in_array('INBOX', $ids) && in_array('SENT', $ids) && in_array('DRAFT', $ids) && in_array('TRASH', $ids) && in_array('SPAM', $ids), "System folders must be present, got: " . implode(',', $ids));
    assertTrue(in_array('ALLMAIL', $ids), "ALLMAIL must be present, got: " . implode(',', $ids));
    assertTrue(!in_array('STARRED', $ids) && !in_array('IMPORTANT', $ids) && !in_array('UNREAD', $ids) && !in_array('CHAT', $ids), "Skipped labels must be absent, got: " . implode(',', $ids));
    foreach ($ids as $id) {
      assertTrue(strpos($id, 'CATEGORY_') !== 0, "CATEGORY_ labels must be absent (found $id)");
    }
    $h1 = $backend->GetHierarchy();
    $h2 = $backend->GetHierarchy();
    $h1ids = array_map(function($f) { return $f->serverid; }, $h1);
    $h2ids = array_map(function($f) { return $f->serverid; }, $h2);
    assertEquals($h1ids, $h2ids, "Two GetHierarchy calls must return same ids in same order");
    $names = array_map(function($f) { return $f->displayname; }, $h1);
    assertTrue(in_array('Inbox', $names) && in_array('Sent', $names) && in_array('Drafts', $names) && in_array('Trash', $names) && in_array('Junk', $names) && in_array('All Mail', $names) && in_array('[Imap]/Trash', $names), "Displaynames must include system + ALLMAIL + user label, got: " . implode(',', $names));
    $backend->Logoff();
  });
  runTest('testGetFolderTypes', function() {
    $backend = new BackendGmail();
    $ok = $backend->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
    assertTrue($ok === true, "Logon must succeed with probe disabled");
    $cases = [
      'INBOX' => [SYNC_FOLDER_TYPE_INBOX, 'Inbox'],
      'SENT' => [SYNC_FOLDER_TYPE_SENTMAIL, 'Sent'],
      'DRAFT' => [SYNC_FOLDER_TYPE_DRAFTS, 'Drafts'],
      'TRASH' => [SYNC_FOLDER_TYPE_WASTEBASKET, 'Trash'],
      'SPAM' => [SYNC_FOLDER_TYPE_USER_MAIL, 'Junk'],
      'ALLMAIL' => [SYNC_FOLDER_TYPE_USER_MAIL, 'All Mail'],
    ];
    foreach ($cases as $id => $exp) {
      $f = $backend->GetFolder($id);
      assertEquals($f->serverid, $id, "serverid for $id");
      assertEquals($f->type, $exp[0], "type for $id");
      assertEquals($f->displayname, $exp[1], "displayname for $id");
      assertEquals($f->parentid, "0", "parentid for $id");
    }
    $h = $backend->GetHierarchy();
    $foundUser = false;
    foreach ($h as $f) {
      if ($f->displayname === '[Imap]/Trash') {
        $foundUser = true;
        assertEquals($f->type, SYNC_FOLDER_TYPE_USER_MAIL, "user label type");
        assertEquals($f->parentid, "0", "user label parentid");
      }
    }
    assertTrue($foundUser, "User label [Imap]/Trash must be in hierarchy");
    $backend->Logoff();
  });
  runTest('testStatFolder', function() {
    $backend = new BackendGmail();
    $ok = $backend->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
    assertTrue($ok === true, "Logon must succeed");
    $stat = $backend->StatFolder('INBOX');
    assertEquals($stat['id'], 'INBOX', "StatFolder id");
    assertEquals($stat['parent'], "0", "StatFolder parent");
    assertEquals($stat['mod'], 'Inbox', "StatFolder mod");
    $backend->Logoff();
  });
  runTest('testGetFolderUnknownThrows', function() {
    $backend = new BackendGmail();
    $ok = $backend->Logon('YOUR_GMAIL_ADDRESS', '', 'dummy-probe-disabled');
    assertTrue($ok === true, "Logon must succeed");
    try {
      $backend->GetFolder('STARRED');
      throw new Exception("Expected exception for skipped folder STARRED");
    } catch (Exception $e) {
      $msg = $e->getMessage();
      assertTrue(strpos($msg, 'STARRED') !== false || strpos($msg, 'unknown') !== false, "Exception must mention unknown/skipped folder, got: $msg");
    }
    $backend->Logoff();
  });
}
echo "Tests: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
