<?php
// Renew Gmail watch (Task 9). Run inside a one-off container with the production state volume mounted:
//   docker run --rm --user www-data:www-data -v zpush-gmail_zpush-gmail-state:/var/lib/z-push -v /opt/zpush-gmail/app:/opt/zpush-gmail/app zpush-zpush:latest php /opt/zpush-gmail/bin/watch-renew.php
// Writes /var/lib/z-push/.gmail-watch.json atomically (0600). No secrets printed.
require_once '/opt/zpush-gmail/app/backend/gmail/gmail_api.php';
try {
    $api = new GmailApiClient('/var/lib/z-push/.gmail-oauth.json', '/var/lib/z-push/.gmail-client.json');
    $res = $api->watchMailbox('projects/YOUR_GCP_PROJECT_ID/topics/gmail-push');
    if (!isset($res['historyId']) || !isset($res['expiration'])) {
        fwrite(STDERR, "WATCH_FAIL: unexpected watch response\n");
        exit(1);
    }
    $data = [
        'historyId' => (string)$res['historyId'],
        'expiration' => (string)$res['expiration'],
        'expirationHuman' => gmdate('Y-m-d H:i:s', (int)((int)$res['expiration'] / 1000)) . ' UTC',
        'topic' => 'projects/YOUR_GCP_PROJECT_ID/topics/gmail-push',
        'renewedAt' => gmdate('c'),
    ];
    $file = '/var/lib/z-push/.gmail-watch.json';
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($data), LOCK_EX) === false) { fwrite(STDERR, "WATCH_FAIL: write failed\n"); exit(1); }
    @chmod($tmp, 0600);
    if (!rename($tmp, $file)) { @unlink($tmp); fwrite(STDERR, "WATCH_FAIL: rename failed\n"); exit(1); }
    echo "WATCH_OK historyId=" . $data['historyId'] . " expiration=" . $data['expirationHuman'] . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "WATCH_FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
