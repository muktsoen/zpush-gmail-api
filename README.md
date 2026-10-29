# zpush-gmail-api

A Gmail API backend for Z-Push 2.7.6, so an iPhone (or any Exchange
ActiveSync client) can sync a Gmail mailbox through ActiveSync, without
IMAP. Push is delivered by Google Cloud Pub/Sub, with a polling fallback.

**This repository is not a complete Z-Push installation.** It contains
only the Gmail backend, its tests, and the deployment files that go with
it. Z-Push itself is not included here (see its own licence and
installation docs). To use this backend, install Z-Push 2.7.6, then copy
`app/backend/gmail/` from this repository into the Z-Push installation
directory, keeping the same relative path.

## What is in this repository

| Path | What it is |
| --- | --- |
| `app/backend/gmail/gmail.php` | `BackendGmail`: folder mapping, Diff-based Sync, read/unread, trash, labels, send, smart reply/forward, Stat cache, ChangesSink |
| `app/backend/gmail/gmail_api.php` | `GmailApiClient`: Gmail REST client, token refresh, retry, history, watch, batch metadata fetch |
| `app/backend/gmail/pubsub_receiver.php` | Pub/Sub push receiver: verifies the Google OIDC JWT and writes a wake flag |
| `app/backend/gmail/config.php` | Non-sensitive constants only (scope, file paths, intervals, skipped labels). Secrets are never stored here |
| `app/backend/gmail/README.md` | Backend internals: folder mapping, Sync model, Stat cache, ChangesSink, test notes |
| `Dockerfile` | PHP 8.3 + Apache image with the IMAP extension (reference; build it against a full Z-Push tree, see below) |
| `docker-compose.yml` | Container on `127.0.0.1:8081`, with a state volume and a log volume |
| `apache-zpush.conf` | Apache aliases for ActiveSync, Autodiscover, and `/gmail-pubsub` |
| `bin/watch-renew.php`, `bin/renew-watch.sh` | Gmail `watch` renewal (see "Watch renewal" below) |
| `tests/` | PHP test files for the API client, backend, Pub/Sub receiver, and the quota fixes. They use stubs and placeholder credentials, not a real mailbox |

## Folder mapping

Gmail labels are mapped by label id, not by display name:

- `INBOX` -> Inbox, `SENT` -> Sent, `DRAFT` -> Drafts, `TRASH` -> Trash, `SPAM` -> Junk
- `ALLMAIL` is synthetic ("All Mail"); it is not a Gmail label
- User labels become flat folders, sorted by name
- Skipped, never shown as folders: `STARRED`, `IMPORTANT`, `UNREAD`, `CHAT`, and any `CATEGORY_*` label

Sync is Diff-based: Z-Push compares `GetMessageList` + `StatMessage`
against its state. There is no `GetChanges` interface in Z-Push, so
history is used for wake-up and high-water tracking, not for a custom
Sync exporter. Message lists take metadata/snippet only; the full MIME
is fetched only when a message is opened.

## Deployment

### 1. Google Cloud setup (done once, by hand)

1. Create a Google Cloud project and enable the Gmail API and the
   Pub/Sub API.
2. Create an OAuth client (Desktop app) and a Pub/Sub topic, e.g.
   `projects/YOUR_GCP_PROJECT_ID/topics/gmail-push`.
3. Create a Pub/Sub push subscription for that topic:
   - Push endpoint: `https://YOUR_PUSH_DOMAIN/gmail-pubsub`
   - Authentication: OIDC token, audience
     `https://YOUR_PUSH_DOMAIN/gmail-pubsub`. The service account used
     here only acts as the token identity; it needs no extra role, and
     no service-account key needs to be created.
4. Authorise the mailbox owner with the OAuth client (see "OAuth scope"
   below) and obtain a refresh token. This step is interactive and
   cannot be done by the server alone.

### 2. Token and client files (the sensitive part)

Place exactly these files inside the state volume, at the paths named in
`app/backend/gmail/config.php`:

- `.gmail-oauth.json` — the OAuth token (access + refresh token)
- `.gmail-client.json` — the OAuth client id/secret
- `.gmail-watch.json` — written by the watch renewal script
- `.gmail-highwater.json` — history high-water mark
- `.gmail-wake.json` — Pub/Sub wake flag

Rules that matter:

- Owner `www-data`, mode `0600`, and they must live **inside the state
  volume directory**. Do not mount a single token file into the
  container: token refresh renames the file, and a single-file mount
  will fail with a busy-device error. Mount the whole directory.
- **Never run a one-off container as root to call the Gmail API.**
  A root-run refresh rewrites the token file as root-owned, the
  production `www-data` process can no longer read it, and every Logon
  then fails (this has happened in production). Always use
  `--user www-data:www-data` for one-off containers, e.g. the watch
  renewal script. If ownership is ever polluted, restore it with
  `chown www-data:www-data` + `chmod 0600` on the token files before
  anything else.
- Never commit these files to git. `.gitignore` in this repository
  excludes them, the `secrets/` directory, state, and logs.

### 3. Device password

The phone does not log in with a Gmail password. It logs in with a
local device password whose **bcrypt hash** is configured for the
backend. Generate a hash for your own password (e.g. with PHP
`password_hash`) and put it where the backend expects it, replacing the
`REPLACE_WITH_BCRYPT_HASH` placeholder. The plaintext password is
never stored in this repository, and the placeholder hash here is not
a usable credential.

### 4. Build and run

```sh
# Overlay this repository's app/backend/gmail/ into a Z-Push 2.7.6 tree,
# then build and start:
docker compose build
docker compose up -d
```

The container listens on `127.0.0.1:8081`. Put nginx (or another
reverse proxy) in front of it at `https://YOUR_PUSH_DOMAIN`, proxying
ActiveSync to `127.0.0.1:8081`, and keep the `/gmail-pubsub` location
outside any ActiveSync rate limiting. Add the phone account as an
Exchange account pointing at `YOUR_PUSH_DOMAIN`.

## OAuth scope

The token used in production is scoped to:

```
https://www.googleapis.com/auth/gmail.modify
```

That scope covers reading, sending, moving mail to Trash, marking
read/unread, and managing labels. It does **not** cover permanent
deletion.

**Emptying Trash (or Junk) does not work under this scope, by design
of Google's API, not by a bug in this backend.** Permanent delete
(`DELETE /users/me/messages/{id}`) requires the full scope:

```
https://mail.google.com/
```

Under `gmail.modify`, a permanent-delete call returns HTTP 403
`insufficientPermissions`, the backend reports an error, the messages
stay in Trash on the Gmail side, and the next Sync brings them back
to the phone. If you need "Empty Trash" on the phone to actually
delete, you must consciously upgrade the scope to the full scope in
the Google Cloud consent screen and re-authorise — knowing that a
leaked full-scope token can read, write, and delete all mail. Until
then, empty Trash from the Gmail web UI instead; the phone will sync
the empty Trash afterwards.

## Pub/Sub push and watch renewal

1. When mail changes, Google POSTs to `/gmail-pubsub`.
   `pubsub_receiver.php` verifies the Google OIDC JWT (RS256, issuer,
   expiry, and audience `https://YOUR_PUSH_DOMAIN/gmail-pubsub`),
   rejects anything invalid with 403 without writing anything, and on
   success atomically writes the wake flag (0600) with the new
   history id. The receiver itself makes no Gmail API calls, so a
   push costs zero quota units. One flag file naturally merges
   multiple notifications.
2. `ChangesSink` checks the wake flag every 2 seconds. When a flag is
   present, the held Ping returns and the phone Syncs. The flag is
   only a hint: it never advances per-folder history ids on its own.
3. If Pub/Sub stops for any reason, the polling fallback still runs:
   a profile/history check every 60 seconds (see "Quota" below).
4. A Gmail `watch` lasts 7 days. Renew it before it expires:

```sh
bin/renew-watch.sh
```

That script runs the renewal inside a `--user www-data:www-data`
one-off container against the production state volume (see the
ownership warning above). Run it on a schedule of at most every
6 days (144 hours) — e.g. a cron job that stays silent on success and
only alerts on failure. If renewal fails, Pub/Sub silently stops but
the 60-second polling fallback keeps mail flowing, so the failure is
an alert, not an outage.

## Quota: the bottleneck and the three fixes

Gmail API quota is per project and per user. The per-project limit is
not the bottleneck for a single mailbox; the **per-user per-project
per-minute limit (6,000 units)** is. Approximate costs: `history.list`
2 units, `messages.list` 5, `messages.get` 20 for metadata and 60 for
a full message, `watch` 100 (but watch runs only once every few days).

A phone reconnect is the peak: every folder is listed in parallel,
and before the fixes below that could exceed 6,000 units in a minute.
When that happened, the server used to turn every Gmail error into a
401, so the phone popped the password prompt in a loop.

Three fixes are already in this code:

1. **Stat cache** (`fetchFolderStatList` in `gmail.php`). Before
   listing a folder, the backend fetches the profile history id; if
   it is unchanged since the last listing, the cached list — including
   label ids, internal date, snippet, and headers, so the preview
   fast path does not degrade into full message fetches — is reused.
   A changed history id, a changed cutoff, or a corrupt cache falls
   back to a full listing; dirty data is never served. A per-folder
   file lock gives single-flight behaviour (5-second timeout, then
   fall back to a full listing rather than hanging the phone).
   Measured effect on a 7-folder cold listing: ~16 s cold, ~2 s warm.
2. **Polling fallback 10 s -> 60 s** (`GMAIL_POLL_INTERVAL` in
   `config.php`). Pub/Sub wake checks are still every 2 seconds
   (they cost nothing); only the API-backed fallback poll was
   slowed down.
3. **Logon probe de-duplication.** Logon used to call `labels.list`
   and throw the result away, then `loadLabels` called it again.
   The probe result is now cached for the current request only
   (`cacheLabelsFromProbe`, same skip rules as `loadLabels`), so
   labels are fetched once per request. The cache is per-request by
   construction (a new PHP process per HTTP request), so label
   additions/removals are picked up on the next request.

On top of that, **quota and rate-limit errors no longer become 401**:
a Gmail 403 quota error or 429 is returned as a 503
(`ServiceUnavailableException`, `Retry-After: 300`), so the phone
waits quietly instead of asking for a password. Logon also verifies
the local device password with bcrypt first, so a wrong password
never spends Gmail API quota. A genuine 401 still returns false.
The API client retries 429 up to 3 times, honouring `Retry-After`
(clamped to 0–60 s).

## Known untested items

These were not verified on a real phone at the time this snapshot was
taken. Do not claim them as working without testing them:

- Read/unread sync in both directions (phone <-> Gmail)
- Moving a message to Trash from the phone, moving between labels
- Sending with attachments, reply, and forward from the phone
  (sending from another mail client into this mailbox was tested)
- Permanent delete / emptying Trash under the current scope — see
  "OAuth scope" above; it is expected to fail with 403 until the
  scope is upgraded
- 48 hours of continuous operation without a quota error

## Tests

The `tests/` files are standalone PHP scripts using stub transports;
they do not need a real token or mailbox. They cover the API client
(batch fetch, retry), the backend (folders, messages, history, ping,
smart reply/forward, write, delete), the Pub/Sub receiver and wake
flag, and the three quota fixes (Stat cache, Logon quota behaviour,
Logon label de-duplication). Run them inside a
`--user www-data` container with the state directory mounted as a
directory (not a single file), following the ownership rules above.
Test files in this repository contain only the
`REPLACE_WITH_BCRYPT_HASH` placeholder and placeholder addresses;
replace them with test values if you want the Logon tests to exercise
a real hash.

## Security notes

- Never commit token files, client secrets, refresh tokens, or device
  passwords. This repository contains placeholders only.
- The Pub/Sub receiver accepts only Google-signed JWTs for its exact
  audience; anything else gets 403 and no wake flag.
- The wake flag and watch files are written atomically (temp file +
  rename) with mode 0600.
