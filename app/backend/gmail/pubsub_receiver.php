<?php
// Pub/Sub push receiver (Task 8). Standalone: no Z-Push framework bootstrap, no secrets.
// POST /gmail-pubsub with Authorization: Bearer <Google OIDC JWT> and a Pub/Sub push body:
//   {"message": {"data": base64(json {"emailAddress": ..., "historyId": ...}), "messageId": ...}}
// On valid JWT + valid body, atomically writes the wake flag file, and only then returns 200.
// Invalid JWT -> 403 (no wake write). Malformed body -> 400. Cert fetch failure -> 503 (retryable).
// GET ?health=1 -> 200 "ok" (health check only, never writes wake flag).
// The wake flag is only a hint: ChangesSink reads it, and the following Sync's GetChanges
// is still the authoritative source of changes. Re-delivery is harmless (flag is idempotent).
class PubSubReceiver {
    private $wakeFile;
    private $expectedAud;
    public function __construct($wakeFile, $expectedAud) {
        $this->wakeFile = $wakeFile;
        $this->expectedAud = $expectedAud;
    }
    public function handle($method, $headers, $rawBody, $certsFetcher, $query = []) {
        $headersLower = [];
        foreach ((array)$headers as $k => $v) { $headersLower[strtolower((string)$k)] = $v; }
        if (strtoupper((string)$method) === 'GET') {
            if (isset($query['health']) && (string)$query['health'] === '1') return [200, 'ok'];
            return [405, 'method not allowed'];
        }
        if (strtoupper((string)$method) !== 'POST') return [405, 'method not allowed'];
        $auth = isset($headersLower['authorization']) ? trim((string)$headersLower['authorization']) : '';
        if (stripos($auth, 'Bearer ') !== 0) return [403, 'missing or invalid authorization'];
        $token = trim(substr($auth, 7));
        if ($token === '') return [403, 'missing token'];
        try {
            $certs = call_user_func($certsFetcher);
        } catch (Throwable $e) {
            return [503, 'certs unavailable'];
        }
        if (!is_array($certs) || !isset($certs['keys']) || !is_array($certs['keys'])) {
            return [503, 'certs unavailable'];
        }
        if (!$this->verifyJwt($token, $certs)) return [403, 'invalid token'];
        $body = json_decode((string)$rawBody, true);
        if (!is_array($body) || !isset($body['message']['data'])) return [400, 'malformed body'];
        $innerRaw = $this->base64urlDecode((string)$body['message']['data']);
        if ($innerRaw === false) return [400, 'malformed data'];
        $inner = json_decode($innerRaw, true);
        if (!is_array($inner) || !isset($inner['historyId']) || (string)$inner['historyId'] === '') {
            return [400, 'missing historyId'];
        }
        $flag = [
            'historyId' => (string)$inner['historyId'],
            'receivedAt' => gmdate('c'),
            'emailAddress' => isset($inner['emailAddress']) ? (string)$inner['emailAddress'] : '',
            'messageId' => isset($body['message']['messageId']) ? (string)$body['message']['messageId'] : '',
        ];
        if (!$this->writeWakeFlag($flag)) return [500, 'wake write failed'];
        return [200, 'ok'];
    }
    public function verifyJwt($token, $certs) {
        $parts = explode('.', (string)$token);
        if (count($parts) !== 3) return false;
        list($hB64, $pB64, $sB64) = $parts;
        $header = json_decode((string)$this->base64urlDecode($hB64), true);
        $payload = json_decode((string)$this->base64urlDecode($pB64), true);
        $sig = $this->base64urlDecode($sB64);
        if (!is_array($header) || !is_array($payload) || $sig === false) return false;
        if (!isset($header['alg']) || $header['alg'] !== 'RS256') return false;
        if (!isset($header['kid']) || (string)$header['kid'] === '') return false;
        $key = null;
        foreach ((array)$certs['keys'] as $k) {
            if (isset($k['kid']) && (string)$k['kid'] === (string)$header['kid']) { $key = $k; break; }
        }
        if ($key === null || !isset($key['n']) || !isset($key['e'])) return false;
        $pem = $this->rsaPemFromJwk((string)$key['n'], (string)$key['e']);
        if ($pem === null) return false;
        $signingInput = $hB64 . '.' . $pB64;
        if (openssl_verify($signingInput, $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) return false;
        $now = time();
        if (!isset($payload['iss']) || !in_array($payload['iss'], ['https://accounts.google.com', 'accounts.google.com'], true)) return false;
        if (!isset($payload['aud']) || (string)$payload['aud'] !== (string)$this->expectedAud) return false;
        if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) return false;
        if (isset($payload['iat']) && (int)$payload['iat'] > $now + 300) return false;
        return true;
    }
    private function writeWakeFlag($flag) {
        $dir = dirname($this->wakeFile);
        if (!is_dir($dir)) return false;
        $tmp = $this->wakeFile . '.tmp.' . getmypid();
        $content = json_encode($flag);
        if ($content === false) return false;
        if (file_put_contents($tmp, $content, LOCK_EX) === false) return false;
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->wakeFile)) { @unlink($tmp); return false; }
        return true;
    }
    private function base64urlDecode($data) {
        $data = (string)$data;
        if ($data === '') return false;
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        $out = base64_decode(strtr($data, '-_', '+/'), true);
        return $out;
    }
    private function derLen($len) {
        if ($len < 128) return chr($len);
        $bytes = ''; $tmp = $len;
        while ($tmp > 0) { $bytes = chr($tmp & 0xff) . $bytes; $tmp >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
    private function derInt($data) {
        if (strlen($data) > 0 && (ord($data[0]) & 0x80)) $data = "\x00" . $data;
        return "\x02" . $this->derLen(strlen($data)) . $data;
    }
    private function rsaPemFromJwk($nB64, $eB64) {
        $n = $this->base64urlDecode($nB64); $e = $this->base64urlDecode($eB64);
        if ($n === false || $e === false || $n === '' || $e === '') return null;
        $rsapub = "\x30" . $this->derLen(strlen($this->derInt($n)) + strlen($this->derInt($e))) . $this->derInt($n) . $this->derInt($e);
        $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x03" . $this->derLen(strlen($rsapub) + 1) . "\x00" . $rsapub;
        $spki = "\x30" . $this->derLen(strlen($alg) + strlen($bitString)) . $alg . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
if (PHP_SAPI !== 'cli') {
    $wakeFile = defined('GMAIL_WAKE_FLAG_FILE') ? GMAIL_WAKE_FLAG_FILE : '/var/lib/z-push/.gmail-wake.json';
    $aud = defined('GMAIL_PUBSUB_AUDIENCE') ? GMAIL_PUBSUB_AUDIENCE : 'https://YOUR_PUSH_DOMAIN/gmail-pubsub';
    $receiver = new PubSubReceiver($wakeFile, $aud);
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (empty($headers)) {
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) $headers[str_replace('_', '-', substr($k, 5))] = $v;
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    $raw = file_get_contents('php://input');
    $res = $receiver->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $headers, $raw, function() {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "Accept: application/json\r\n"]]);
        $json = @file_get_contents('https://www.googleapis.com/oauth2/v3/certs', false, $ctx);
        if ($json === false) throw new Exception('cert fetch failed');
        $data = json_decode($json, true);
        if (!is_array($data)) throw new Exception('cert parse failed');
        return $data;
    }, $_GET ?? []);
    http_response_code($res[0]);
    header('Content-Type: text/plain');
    echo $res[1];
}
