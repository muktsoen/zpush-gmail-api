# Hybrid push: Pub/Sub wake + IMAP sync

One pipeline, two wake triggers, mail always over IMAP. This is the
follow-up to the pure Gmail API backend in the rest of this repository:
instead of syncing mail through the Gmail API, the phone keeps syncing
through IMAP, and Gmail Pub/Sub is only used to wake a waiting Ping
earlier. If Pub/Sub (or the watch) dies, the system falls back to plain
IMAP IDLE. Nothing here syncs mail through the API, so it burns almost
no Gmail API quota (one `users.watch` call per renewal).

## How it works

1. A new message reaches Gmail.
2. Two wake paths race inside one wait loop:
   - **IMAP IDLE**: Z-Push holds one IDLE connection per watched folder
     on `imap.gmail.com:993`. Gmail pushes EXISTS/EXPUNGE itself.
   - **Pub/Sub**: Gmail notifies a Pub/Sub topic, Google POSTs to the
     push endpoint, `receiver.py` verifies the OIDC JWT (RS256, JWKS
     from Google's certs endpoint) and, only for fresh notifications
     (published within the last 5 minutes), updates a wake-flag file.
     Stale backlog messages are logged, never used to wake.
3. The phone holds a long EAS Ping. `ImapIdleSink::wait()` slices its
   wait into 2-second pieces and checks both IDLE sockets and the wake
   flag each slice. Whichever fires first returns the Ping.
4. A Pub/Sub notification names no folder, so on a wake the sink
   returns **all** watched folders. The phone then Syncs over IMAP,
   which finds the real diffs; folders without changes report none.
5. After Sync, the phone issues a new Ping and waits again.

The flag is a **timestamp, never a boolean, and never consumed**: a new
Ping starts after the flag time, so the same message cannot wake it a
second time. That is what prevents double notifications: Pub/Sub and
IDLE can never both return the same Ping, and a later Ping is not
re-triggered by an old flag.

## Files in this directory

- `receiver.py` — host-side Pub/Sub receiver (systemd, `127.0.0.1:8081`
  behind nginx). Verifies Google's JWT, logs one line per verified
  notification, writes the wake flag for fresh ones only. Replace
  `YOUR_PUSH_DOMAIN` with the push endpoint's host; the audience must
  equal the exact push URL.
- `imap_idle_sink.patch` — the Z-Push change, as a unified diff against
  the IDLE-patched `backend/imap/imap_idle_sink.php` from Z-Push 2.7.7
  (the PR #192 IDLE file; that file itself is AGPL, see its header).
  It adds the wake-flag constant, 2-second wait slices, and the wake
  check. Apply with `patch` inside a copy of the Z-Push tree, then
  rebuild the container.
- `gmail-watch-renew.py` — host-side watch renewal (stdlib only). Run
  as `www-data`, never as root. Renews only when the stored expiration
  is under 48h away; writes watch state atomically (0600).
- `gmail-watch-exchange.py` — one-off exchange of an authorization code
  (stdin) for a refresh token, stored 0600. The code is single-use.

## Setup sketch

1. Apply the patch, rebuild the Z-Push image, and add the bind mount
   for the receiver directory into the container, e.g.:
   `/opt/pubsub-test:/var/lib/z-push/pubsub` (the sink reads
   `/var/lib/z-push/pubsub/wake.flag` through it; the receiver must
   write the flag in place, not rename-replace it, or the mount keeps
   pointing at the old inode).
2. Run `receiver.py` as a systemd service (`www-data`) and proxy the
   push endpoint to it from nginx. The mail endpoint keeps proxying to
   Z-Push.
3. Create a Gmail watch (`users.watch`, scope `gmail.modify`) with the
   topic `projects/YOUR_GCP_PROJECT_ID/topics/gmail-push` and the push
   endpoint. A watch lasts 7 days.
4. Exchange one authorization code with `gmail-watch-exchange.py`,
   store the OAuth client JSON and the refresh token as `www-data`
   0600 files next to `gmail-watch-renew.py`, and run the renew script
   from cron (daily is enough; it only renews when under 48h left).

## Measured timings (production, 2026-11-01)

Send to phone-Sync done in about 21–23 seconds end to end: Gmail
visible in 1–6 s, Pub/Sub delivered in ~7 s, wake logged ~2 s later,
Ping returned, Sync done in ~4 s. Pure IDLE on the same setup measured
13–22 s to detect. The hybrid saves roughly ten seconds; it cannot fix
a phone that is not holding a Ping at all, which is a separate,
phone-side failure mode.

## Gotchas found while building it

- **PHP stat cache**: `filemtime()` results are cached for the whole
  request. A long Ping that checks the flag without
  `clearstatcache(true, $file)` first will never see an update. The
  patch includes that call; without it the wake path silently never
  fires.
- **JWKS, not cert dicts**: Google's certs endpoint now returns
  `{"keys": [...]}` (JWKS). Parsing it as the old kid-to-certificate
  map yields an empty key set and every push fails with "unknown kid".
- **Empty socket set + wake gap (fixed 2026-11-01, per upstream
  review)**: if every IDLE connection closes mid-Ping, the sliced loop
  must sleep the slice and keep checking the flag, never call
  `stream_select()` with empty arrays (PHP 8 throws `ValueError`,
  an `Error` no `catch (Exception)` stops, and the Ping dies). And
  the flag is compared against the last mtime this sink acted on
  (baselined at the first `wait()` of a Ping), not against the current
  `wait()` start, so a wake written while `CheckForChanges()` validates
  a previous notification is still seen by the next `wait()`.
- **Wake list + constructor baseline (fixed 2026-11-01, per a second
  upstream review)**: a wake returns the folders ever passed to
  `addFolder()`, not the live sockets, so a folder whose IDLE
  connection the server closed is still reported instead of the wake
  being consumed while `wait()` returns `[]`. And the baseline is
  taken in the constructor, not on the first `wait()`, so a wake
  written while the folder pool is being built is not swallowed into
  the baseline.
- **Receiver robustness (fixed 2026-11-01)**: publishTime parsing
  accepts a missing fraction or 1-9 fractional digits (a timestamp in
  any other legal RFC3339 shape used to skip the wake silently while
  still ACKing 200); auth is checked before the body is read (bad
  length 400, over 1 MB 413); a failure fetching Google's certs
  answers 503 so Pub/Sub retries instead of 403 dropping the push.
  The watch-renew script also fails cleanly on network errors
  instead of an uncaught traceback.
- **1-second race**: a flag written just before a new Ping starts is
  missed by design (the new wait starts after the flag). It only
  matters when mail arrives exactly at a Ping handover; an idle phone
  with a long Ping waiting is unaffected.
- **Never run token jobs as root**: a root-run renewal once made token
  files root-owned and unreadable by production, which surfaced as
  password prompts on the phone. Everything credential-related here
  runs as `www-data`.

## Fallbacks, in order

Watch dead → plain IDLE. IDLE broken → the backend's existing status
polling. Receiver down → only the wake path is lost; mail sync is
unaffected. Container down → restart policy brings it back and the
phone re-Pings. No level drops mail.

## Security notes

No secrets live in this repository, only placeholders. At runtime the
OAuth client JSON, the refresh token, and the watch state are
`www-data` 0600 files on the host. The receiver logs message IDs,
timestamps, and history IDs only — never tokens or bodies.
