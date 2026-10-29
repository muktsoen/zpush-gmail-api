#!/bin/bash
# Renew Gmail Pub/Sub watch (Task 9). Safe to re-run: watchMailbox renews/creates a channel, expiration moves to now+7 days.
# Log goes to /var/log/gmail-watch-renew.log on the VPS host.
{
  echo "=== $(date -u '+%Y-%m-%d %H:%M:%S UTC') renew-watch start ==="
  docker run --rm --user www-data:www-data -v zpush-gmail_zpush-gmail-state:/var/lib/z-push -v /opt/zpush-gmail:/opt/zpush-gmail zpush-zpush:latest php /opt/zpush-gmail/bin/watch-renew.php
  echo "exit=$?"
} 2>&1 | tee -a /var/log/gmail-watch-renew.log
