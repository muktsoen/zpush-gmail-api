#!/usr/bin/env python3
"""Gmail Pub/Sub receiver for the hybrid push design (wake via Pub/Sub,
mail handling stays on IMAP).

- Verifies Google's OIDC JWT (RS256, aud = the push endpoint, iss = Google).
- On a verified notification, appends ONE log line with timestamps,
  messageId, publishTime and historyId. If the notification is fresh
  (published within the last 5 minutes), it also updates the wake flag
  that Z-Push's IDLE wait polls: stale backlog messages do NOT wake,
  so a backlog drain cannot make the phone sync in a loop.
  It does NOT touch mail or Z-Push state files.
- On an invalid token, returns 403 and logs only a count/reason, never the
  token or the request body.
- GET ?health=1 returns 200 for health checks.
"""
import base64
import json
import logging
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

import jwt

AUDIENCE = "https://YOUR_PUSH_DOMAIN/gmail-pubsub"
ISSUERS = {"https://accounts.google.com", "accounts.google.com"}
CERTS_URL = "https://www.googleapis.com/oauth2/v3/certs"
LOG_PATH = "/opt/pubsub-test/pubsub.log"
WAKE_PATH = "/opt/pubsub-test/wake.flag"
WAKE_FRESH_SECONDS = 300
HOST, PORT = "127.0.0.1", 8081

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(LOG_PATH), logging.StreamHandler()],
)
log = logging.getLogger("pubsub-test")

_certs = {"at": 0, "keys": {}}


def get_certs(force=False):
    now = time.time()
    if not force and _certs["keys"] and now - _certs["at"] < 3600:
        return _certs["keys"]
    req = urllib.request.Request(CERTS_URL, headers={"User-Agent": "pubsub-test/1.0"})
    with urllib.request.urlopen(req, timeout=10) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    keys = {}
    if isinstance(data, dict) and isinstance(data.get("keys"), list):
        # Current format: JWKS {"keys": [{"kid", "n", "e", ...}, ...]}.
        for jwk in data["keys"]:
            try:
                keys[jwk["kid"]] = jwt.algorithms.RSAAlgorithm.from_jwk(json.dumps(jwk))
            except Exception:
                continue
    elif isinstance(data, dict):
        # Older format: {kid: PEM certificate}.
        for kid, cert in data.items():
            try:
                from cryptography.x509 import load_pem_x509_certificate

                keys[kid] = load_pem_x509_certificate(cert.encode("utf-8")).public_key()
            except Exception:
                continue
    _certs["at"], _certs["keys"] = now, keys
    return keys


def verify_token(token):
    header = jwt.get_unverified_header(token)
    keys = get_certs()
    key = keys.get(header.get("kid"))
    if key is None:
        key = get_certs(force=True).get(header.get("kid"))
    if key is None:
        raise ValueError("unknown kid")
    payload = jwt.decode(
        token, key, algorithms=["RS256"], audience=AUDIENCE,
        options={"require": ["exp", "iat", "aud", "iss"]},
    )
    if payload.get("iss") not in ISSUERS:
        raise ValueError("bad issuer")
    return payload


def now_iso():
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, code, body):
        data = body.encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        qs = parse_qs(urlparse(self.path).query)
        if qs.get("health") == ["1"]:
            self._send(200, "ok\n")
        else:
            self._send(404, "not found\n")

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        auth = self.headers.get("Authorization") or ""
        if not auth.startswith("Bearer "):
            log.warning(json.dumps({"verified": False, "reason": "missing bearer", "at": now_iso()}))
            self._send(403, "forbidden\n")
            return
        try:
            verify_token(auth[7:])
        except Exception as exc:
            log.warning(json.dumps({"verified": False, "reason": type(exc).__name__ + ": " + str(exc)[:80], "at": now_iso()}))
            self._send(403, "forbidden\n")
            return
        try:
            body = json.loads(raw.decode("utf-8"))
            msg = body["message"]
            data = json.loads(base64.urlsafe_b64decode(msg["data"] + "==").decode("utf-8"))
            entry = {
                "verified": True,
                "received_utc": now_iso(),
                "messageId": msg.get("messageId"),
                "publishTime": msg.get("publishTime"),
                "historyId": data.get("historyId"),
            }
        except Exception as exc:
            log.warning(json.dumps({"verified": False, "reason": "bad body: " + type(exc).__name__, "at": now_iso()}))
            self._send(400, "bad request\n")
            return
        log.info(json.dumps(entry))
        # Wake Z-Push only for fresh notifications. The flag is written
        # in place (same inode) because Z-Push reads it through a bind
        # mount; a rename would leave the container seeing the old file.
        try:
            from datetime import datetime, timezone

            pub = datetime.strptime(msg.get("publishTime", ""), "%Y-%m-%dT%H:%M:%S.%fZ").replace(tzinfo=timezone.utc)
            if time.time() - pub.timestamp() <= WAKE_FRESH_SECONDS:
                with open(WAKE_PATH, "w") as f:
                    f.write(now_iso() + " historyId=" + str(data.get("historyId")) + "\n")
        except Exception as exc:
            log.warning(json.dumps({"wake_write_failed": type(exc).__name__, "at": now_iso()}))
        self._send(200, "ok\n")


if __name__ == "__main__":
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
