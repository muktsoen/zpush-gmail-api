<?php
// Pub/Sub receiver tests (Task 8) - plain PHP, no PHPUnit. No secrets, no real Google calls.
// Run:
//   docker run --rm --user www-data -v /opt/zpush-gmail:/opt/zpush-gmail zpush-zpush:latest php /opt/zpush-gmail/tests/pubsub_receiver_test.php
// Exit 0 if all pass, 1 otherwise.
$loaded = @include_once '/opt/zpush-gmail/app/backend/gmail/pubsub_receiver.php';
if (!class_exists('PubSubReceiver')) {
  echo "FAIL: setup - PubSubReceiver class not found\n";
  exit(1);
}
$failures = 0; $passed = 0;
function runTest($name, $fn) {
  global $failures, $passed;
  try { $fn(); $passed++; echo "PASS: $name\n"; }
  catch (Throwable $e) { $failures++; echo "FAIL: $name - " . $e->getMessage() . "\n"; }
}
function assertTrue($c, $m) { if (!$c) throw new Exception($m); }
function assertEquals($a, $b, $m) { if ($a !== $b) throw new Exception($m . " (expected " . var_export($b, true) . ", got " . var_export($a, true) . ")"); }
function b64u($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function derLen($len) {
  if ($len < 128) return chr($len);
  $bytes = ''; $tmp = $len;
  while ($tmp > 0) { $bytes = chr($tmp & 0xff) . $bytes; $tmp >>= 8; }
  return chr(0x80 | strlen($bytes)) . $bytes;
}
function derInt($data) {
  if (strlen($data) > 0 && (ord($data[0]) & 0x80)) $data = "\x00" . $data;
  return "\x02" . derLen(strlen($data)) . $data;
}
function rsaPemFromJwk($nB64, $eB64) {
  $n = base64_decode(strtr($nB64, '-_', '+/')); $e = base64_decode(strtr($eB64, '-_', '+/'));
  $rsapub = "\x30" . derLen(strlen(derInt($n)) + strlen(derInt($e))) . derInt($n) . derInt($e);
  $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
  $spki = "\x30" . derLen(strlen($alg) + 1 + 1 + strlen($rsapub)) . $alg . "\x03" . derLen(strlen($rsapub) + 1) . "\x00" . $rsapub;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function makeKeyAndCerts() {
  $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
  if ($res === false) throw new Exception('openssl keygen failed');
  $details = openssl_pkey_get_details($res);
  $n = b64u($details['rsa']['n']); $e = b64u($details['rsa']['e']);
  $certs = ['keys' => [['kid' => 'test-kid-1', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $n, 'e' => $e]]];
  return [$res, $certs];
}
function makeJwt($priv, $claims, $kid = 'test-kid-1', $alg = 'RS256') {
  $header = b64u(json_encode(['alg' => $alg, 'kid' => $kid, 'typ' => 'JWT']));
  $payload = b64u(json_encode($claims));
  $sigInput = $header . '.' . $payload;
  if (!openssl_sign($sigInput, $sig, $priv, OPENSSL_ALGO_SHA256)) throw new Exception('sign failed');
  return $sigInput . '.' . b64u($sig);
}
function tmpWake() { $f = tempnam(sys_get_temp_dir(), 'wake'); unlink($f); return $f . '.json'; }
$aud = 'https://YOUR_PUSH_DOMAIN/gmail-pubsub';
runTest('testValidJwtWritesWakeAndReturns200', function() use ($aud) {
  list($priv, $certs) = makeKeyAndCerts();
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $now = time();
  $jwt = makeJwt($priv, ['iss' => 'https://accounts.google.com', 'aud' => $aud, 'exp' => $now + 600, 'iat' => $now, 'sub' => '123', 'email' => 'test@example.com']);
  $body = json_encode(['message' => ['data' => base64_encode(json_encode(['emailAddress' => 'YOUR_GMAIL_ADDRESS', 'historyId' => '999001'])), 'messageId' => 'msg-1', 'publishTime' => '2026-10-29T00:00:00Z'], 'subscription' => 'projects/x/subscriptions/y']);
  $res = $r->handle('POST', ['authorization' => 'Bearer ' . $jwt], $body, function() use ($certs) { return $certs; });
  assertEquals(200, $res[0], 'valid push must return 200');
  $data = json_decode(file_get_contents($wake), true);
  assertTrue(is_array($data), 'wake file must be JSON');
  assertEquals('999001', (string)$data['historyId'], 'wake historyId must match push');
  unlink($wake);
});
runTest('testInvalidSignatureReturns403AndNoWake', function() use ($aud) {
  list($priv, $certs) = makeKeyAndCerts();
  list($priv2, $certs2) = makeKeyAndCerts();
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $now = time();
  $jwt = makeJwt($priv2, ['iss' => 'https://accounts.google.com', 'aud' => $aud, 'exp' => $now + 600, 'iat' => $now]);
  $body = json_encode(['message' => ['data' => base64_encode(json_encode(['emailAddress' => 'YOUR_GMAIL_ADDRESS', 'historyId' => '999002'])), 'messageId' => 'msg-2']]);
  $res = $r->handle('POST', ['authorization' => 'Bearer ' . $jwt], $body, function() use ($certs) { return $certs; });
  assertEquals(403, $res[0], 'wrong-key JWT must return 403');
  assertTrue(!file_exists($wake), 'invalid JWT must not write wake flag');
});
runTest('testExpiredJwtReturns403', function() use ($aud) {
  list($priv, $certs) = makeKeyAndCerts();
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $now = time();
  $jwt = makeJwt($priv, ['iss' => 'https://accounts.google.com', 'aud' => $aud, 'exp' => $now - 60, 'iat' => $now - 660]);
  $body = json_encode(['message' => ['data' => base64_encode(json_encode(['emailAddress' => 'YOUR_GMAIL_ADDRESS', 'historyId' => '999003'])), 'messageId' => 'msg-3']]);
  $res = $r->handle('POST', ['authorization' => 'Bearer ' . $jwt], $body, function() use ($certs) { return $certs; });
  assertEquals(403, $res[0], 'expired JWT must return 403');
  assertTrue(!file_exists($wake), 'expired JWT must not write wake flag');
});
runTest('testWrongAudienceReturns403', function() use ($aud) {
  list($priv, $certs) = makeKeyAndCerts();
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $now = time();
  $jwt = makeJwt($priv, ['iss' => 'https://accounts.google.com', 'aud' => 'https://evil.example/gmail-pubsub', 'exp' => $now + 600, 'iat' => $now]);
  $body = json_encode(['message' => ['data' => base64_encode(json_encode(['emailAddress' => 'YOUR_GMAIL_ADDRESS', 'historyId' => '999004'])), 'messageId' => 'msg-4']]);
  $res = $r->handle('POST', ['authorization' => 'Bearer ' . $jwt], $body, function() use ($certs) { return $certs; });
  assertEquals(403, $res[0], 'wrong aud must return 403');
  assertTrue(!file_exists($wake), 'wrong aud must not write wake flag');
});
runTest('testMissingAuthReturns403', function() use ($aud) {
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $body = json_encode(['message' => ['data' => base64_encode(json_encode(['emailAddress' => 'YOUR_GMAIL_ADDRESS', 'historyId' => '999005'])), 'messageId' => 'msg-5']]);
  $res = $r->handle('POST', [], $body, function() { return ['keys' => []]; });
  assertEquals(403, $res[0], 'missing auth must return 403');
  assertTrue(!file_exists($wake), 'missing auth must not write wake flag');
});
runTest('testMalformedBodyReturns400', function() use ($aud) {
  list($priv, $certs) = makeKeyAndCerts();
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $now = time();
  $jwt = makeJwt($priv, ['iss' => 'https://accounts.google.com', 'aud' => $aud, 'exp' => $now + 600, 'iat' => $now]);
  $res = $r->handle('POST', ['authorization' => 'Bearer ' . $jwt], '{not json', function() use ($certs) { return $certs; });
  assertEquals(400, $res[0], 'malformed body must return 400');
  assertTrue(!file_exists($wake), 'malformed body must not write wake flag');
});
runTest('testHealthCheckDoesNotWriteWake', function() use ($aud) {
  $wake = tmpWake();
  $r = new PubSubReceiver($wake, $aud);
  $res = $r->handle('GET', [], '', function() { return ['keys' => []]; }, ['health' => '1']);
  assertEquals(200, $res[0], 'health check must return 200');
  assertTrue(!file_exists($wake), 'health check must not write wake flag');
});
echo "pubsub_receiver: $passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
