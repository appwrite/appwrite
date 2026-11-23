#!/usr/bin/env bash
set -euo pipefail

# Console sessions are capped per user and the oldest is evicted first, so a
# session left behind by every run eventually signs out a run still in flight.

cookies=$(printf '%s\n' "$SESSION" | openssl enc -d -aes-256-cbc -pbkdf2 -a -A -pass env:E2E_TEST_PASSWORD)
echo "::add-mask::$cookies"

status=$(
  curl --silent --show-error \
    --request DELETE "${VITE_APPWRITE_ENDPOINT%/}/account/sessions/current" \
    --header 'X-Appwrite-Project: console' \
    --header "X-Fallback-Cookies: $cookies" \
    --output /dev/null \
    --write-out '%{http_code}'
)

case "$status" in
  204) echo 'Deleted the console session' ;;
  401) echo '::warning::The console session was already gone (evicted or expired)' ;;
  *)
    echo "::error::Deleting the console session failed ($status)"
    exit 1
    ;;
esac
