#!/usr/bin/env bash
set -euo pipefail

headers=$(mktemp)
body=$(mktemp)

status=$(
  jq -n --arg email "$E2E_TEST_EMAIL" --arg password "$E2E_TEST_PASSWORD" \
    '{email: $email, password: $password}' |
  curl --silent --show-error \
    --request POST "${VITE_APPWRITE_ENDPOINT%/}/account/sessions/email" \
    --header 'Content-Type: application/json' \
    --header 'X-Appwrite-Project: console' \
    --data @- \
    --dump-header "$headers" \
    --output "$body" \
    --write-out '%{http_code}'
)

if [ "$status" != 201 ]; then
  echo "::error::Sign-in failed ($status): $(jq -r '.message // empty' "$body")"
  exit 1
fi

cookies=$(sed -n 's/^x-fallback-cookies: *//Ip' "$headers" | tr -d '\r')
if [ -z "$cookies" ]; then
  echo '::error::Sign-in returned no X-Fallback-Cookies header'
  exit 1
fi

echo "::add-mask::$cookies"
session=$(printf '%s' "$cookies" | openssl enc -aes-256-cbc -pbkdf2 -salt -a -A -pass env:E2E_TEST_PASSWORD)
echo "session=$session" >> "$GITHUB_OUTPUT"
