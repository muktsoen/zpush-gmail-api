#!/usr/bin/env python3
"""One-off exchange: authorization code (stdin) -> refresh token.

Reads the OAuth client from /opt/gmail-watch/.gmail-client.json (0600,
www-data only), posts the code to Google's token endpoint, and stores
the refresh token at /opt/gmail-watch/.gmail-refresh-token (0600).
Prints only status, never codes or tokens.
"""
import json
import os
import sys
import urllib.parse
import urllib.request

BASE = "/opt/gmail-watch"
CLIENT_FILE = os.path.join(BASE, ".gmail-client.json")
TOKEN_FILE = os.path.join(BASE, ".gmail-refresh-token")
TOKEN_URL = "https://oauth2.googleapis.com/token"


def fail(msg):
    print("EXCHANGE_FAIL: " + msg, file=sys.stderr)
    sys.exit(1)


def main():
    code = sys.stdin.read().strip()
    if not code:
        fail("empty code on stdin")
    try:
        with open(CLIENT_FILE) as f:
            client = json.load(f)
    except (FileNotFoundError, ValueError, OSError) as e:
        fail("cannot read client file: " + type(e).__name__)

    payload = urllib.parse.urlencode({
        "code": code,
        "client_id": client["client_id"],
        "client_secret": client["client_secret"],
        "redirect_uri": "http://localhost",
        "grant_type": "authorization_code",
    }).encode("utf-8")
    req = urllib.request.Request(TOKEN_URL, data=payload,
                                 headers={"Content-Type": "application/x-www-form-urlencoded"})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            res = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")[:300]
        fail("HTTP %d: %s" % (e.code, body))
    except OSError as e:
        fail("network: " + type(e).__name__)

    refresh = res.get("refresh_token")
    if not refresh:
        fail("no refresh_token in response (re-consent with prompt=consent and access_type=offline, then retry with a fresh code)")
    tmp = TOKEN_FILE + ".tmp." + str(os.getpid())
    with open(tmp, "w") as f:
        f.write(refresh + "\n")
    os.chmod(tmp, 0o600)
    os.rename(tmp, TOKEN_FILE)
    print("EXCHANGE_OK refresh_token stored; access_token expires in %s seconds" % res.get("expires_in", "?"))


if __name__ == "__main__":
    main()
