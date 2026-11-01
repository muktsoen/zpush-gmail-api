#!/usr/bin/env python3
"""Renew the Gmail Pub/Sub watch for the hybrid push setup (host-based).

Reads the OAuth client + refresh token from /opt/gmail-watch/ (0600,
www-data only), refreshes the access token, and calls users.watch.
Renews only when the stored expiration is under 48h away; otherwise
exits 0 with NOT_DUE. Writes watch state atomically (0600).
Never prints secrets or tokens.
"""
import json
import os
import sys
import time
import urllib.error
import urllib.request

BASE = "/opt/gmail-watch"
CLIENT_FILE = os.path.join(BASE, ".gmail-client.json")
TOKEN_FILE = os.path.join(BASE, ".gmail-refresh-token")
STATE_FILE = os.path.join(BASE, ".gmail-watch.json")
TOPIC = "projects/YOUR_GCP_PROJECT_ID/topics/gmail-push"
TOKEN_URL = "https://oauth2.googleapis.com/token"
WATCH_URL = "https://gmail.googleapis.com/gmail/v1/users/me/watch"
RENEW_WITHIN_SECONDS = 48 * 3600


def fail(msg):
    print("WATCH_FAIL: " + msg, file=sys.stderr)
    sys.exit(1)


def post_json(url, payload, access_token=None):
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"})
    if access_token:
        req.add_header("Authorization", "Bearer " + access_token)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")[:300]
        fail("HTTP %d from %s: %s" % (e.code, url, body))
    except (urllib.error.URLError, OSError) as e:
        # Network failure (DNS, timeout, refused): fail cleanly with a
        # WATCH_FAIL line instead of an uncaught traceback. The cron
        # retries daily and the 48h window gives two attempts; expiry
        # only falls back to IDLE, it never drops mail.
        fail("network from %s: %s" % (url, type(e).__name__))


def main():
    try:
        with open(CLIENT_FILE) as f:
            client = json.load(f)
        with open(TOKEN_FILE) as f:
            refresh_token = f.read().strip()
    except FileNotFoundError as e:
        fail("missing credential file: " + os.path.basename(e.filename))
    except (ValueError, OSError) as e:
        fail("cannot read credentials: " + type(e).__name__)

    if os.path.exists(STATE_FILE):
        try:
            with open(STATE_FILE) as f:
                state = json.load(f)
            exp_ms = int(state.get("expiration", "0"))
            if exp_ms / 1000 - time.time() > RENEW_WITHIN_SECONDS:
                print("NOT_DUE expiration=" + state.get("expirationHuman", "?"))
                return
        except (ValueError, OSError, KeyError):
            pass  # unreadable state -> renew below

    token = post_json(TOKEN_URL, {
        "client_id": client["client_id"],
        "client_secret": client["client_secret"],
        "refresh_token": refresh_token,
        "grant_type": "refresh_token",
    })
    access_token = token.get("access_token")
    if not access_token:
        fail("token refresh returned no access_token")

    res = post_json(WATCH_URL, {"topicName": TOPIC}, access_token)
    if "historyId" not in res or "expiration" not in res:
        fail("unexpected watch response keys: " + ",".join(sorted(res.keys())))

    exp_ms = int(res["expiration"])
    data = {
        "historyId": str(res["historyId"]),
        "expiration": str(res["expiration"]),
        "expirationHuman": time.strftime("%Y-%m-%d %H:%M:%S UTC", time.gmtime(exp_ms / 1000)),
        "topic": TOPIC,
        "renewedAt": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    tmp = STATE_FILE + ".tmp." + str(os.getpid())
    with open(tmp, "w") as f:
        json.dump(data, f)
    os.chmod(tmp, 0o600)
    os.rename(tmp, STATE_FILE)
    print("WATCH_OK historyId=" + data["historyId"] + " expiration=" + data["expirationHuman"])


if __name__ == "__main__":
    main()
