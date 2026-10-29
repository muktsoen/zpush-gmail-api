<?php
// Gmail backend configuration (non-sensitive only; secrets live in token/client files, never here).
// All constants use if (!defined()) guards so tests can pre-define overrides (e.g. GMAIL_LOGON_IMAP_PROBE) before including this file.
if (!defined('GMAIL_API_BASE')) define('GMAIL_API_BASE', 'https://gmail.googleapis.com/gmail/v1');
if (!defined('GMAIL_OAUTH_SCOPE')) define('GMAIL_OAUTH_SCOPE', 'https://www.googleapis.com/auth/gmail.modify');
if (!defined('GMAIL_TOKEN_FILE')) define('GMAIL_TOKEN_FILE', '/var/lib/z-push/.gmail-oauth.json');
if (!defined('GMAIL_CLIENT_FILE')) define('GMAIL_CLIENT_FILE', '/var/lib/z-push/.gmail-client.json');
if (!defined('GMAIL_WAKE_FLAG_FILE')) define('GMAIL_WAKE_FLAG_FILE', '/var/lib/z-push/.gmail-wake.json');
if (!defined('GMAIL_POLL_INTERVAL')) define('GMAIL_POLL_INTERVAL', 60);
if (!defined('GMAIL_PUBSUB_FALLBACK_POLL')) define('GMAIL_PUBSUB_FALLBACK_POLL', 60);
if (!defined('GMAIL_RECONCILE_INTERVAL')) define('GMAIL_RECONCILE_INTERVAL', 900);
if (!defined('GMAIL_WAKE_CHECK_INTERVAL')) define('GMAIL_WAKE_CHECK_INTERVAL', 2);
if (!defined('GMAIL_LOGON_IMAP_PROBE')) define('GMAIL_LOGON_IMAP_PROBE', false);
if (!defined('GMAIL_TEST_DEVICE_PASSWORD_HASH')) define('GMAIL_TEST_DEVICE_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH');
if (!defined('GMAIL_IMAP_SERVER')) define('GMAIL_IMAP_SERVER', 'imap.gmail.com');
if (!defined('GMAIL_IMAP_PORT')) define('GMAIL_IMAP_PORT', 993);
if (!defined('GMAIL_IMAP_OPTIONS')) define('GMAIL_IMAP_OPTIONS', '/ssl');
if (!defined('GMAIL_SKIP_LABELS')) define('GMAIL_SKIP_LABELS', ['STARRED', 'IMPORTANT', 'UNREAD', 'CHAT']);
if (!defined('GMAIL_SKIP_LABEL_PREFIXES')) define('GMAIL_SKIP_LABEL_PREFIXES', ['CATEGORY_']);
if (!defined('GMAIL_ALLMAIL_ID')) define('GMAIL_ALLMAIL_ID', 'ALLMAIL');
if (!defined('GMAIL_HIGHWATER_FILE')) define('GMAIL_HIGHWATER_FILE', '/var/lib/z-push/.gmail-highwater.json');
if (!defined('GMAIL_PUBSUB_AUDIENCE')) define('GMAIL_PUBSUB_AUDIENCE', 'https://YOUR_PUSH_DOMAIN/gmail-pubsub');
