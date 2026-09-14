#!/usr/bin/env bash
# Reads back everything upgrade-seed.sh created and asserts it survived the
# migration unchanged, then writes new data to prove the upgraded install is
# still usable. Run against the TARGET version, after migrate.
#
#   ENDPOINT=http://localhost:8080/v1 MANIFEST=upgrade-seed.json ./upgrade-verify.sh

set -euo pipefail

# shellcheck source=.github/scripts/upgrade-lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/upgrade-lib.sh"

[ -f "$MANIFEST" ] || fail "No manifest at ${MANIFEST}; did upgrade-seed.sh run?"

FAILURES=0

PROJECT_ID=$(manifest_get '.projectId'); export PROJECT_ID
API_KEY=$(manifest_get '.apiKey'); export API_KEY
DATABASE_ID=$(manifest_get '.databaseId')
AUTHORS_TABLE=$(manifest_get '.authorsTable')
POSTS_TABLE=$(manifest_get '.postsTable')
USER_ID=$(manifest_get '.userId')
BUCKET_ID=$(manifest_get '.bucketId')
FILE_ID=$(manifest_get '.fileId')
LONG_STRING=$(manifest_get '.longString')
UNICODE_STRING=$(manifest_get '.unicodeString')
SEEDED_ON=$(manifest_get '.seededOn')

wait_for_appwrite
UPGRADED_TO=$(server_version)

echo "Verifying upgrade ${SEEDED_ON} -> ${UPGRADED_TO}"

if [ "$SEEDED_ON" = "$UPGRADED_TO" ]; then
    fail "Still reporting ${UPGRADED_TO}; the image did not change, so this run proves nothing."
fi

# --- the API key still authenticates -----------------------------------------

# The project, its API key and its scopes all have to survive the migration
# before any of the assertions below mean anything.
echo "==> Project access"
if ! api GET /users >/dev/null 2>&1; then
    fail "The seeded API key no longer authenticates against project ${PROJECT_ID}."
fi
echo "  ok    seeded API key still authenticates"

# --- seeded rows survived ----------------------------------------------------

echo "==> Rows"
row() { api GET "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows/$1"; }

empty=$(row post-empty)
assert_equals "post-empty title" "Empty body" "$(jq -r '.title' <<<"$empty")"
assert_equals "post-empty kept its empty string" "" "$(jq -r '.body' <<<"$empty")"
assert_equals "post-empty kept its column default" "untagged" "$(jq -r '.tag' <<<"$empty")"
assert_equals "post-empty kept its relationship" "$(manifest_get '.authorId')" \
    "$(jq -r 'if (.author | type) == "object" then .author["$id"] else .author end' <<<"$empty")"

long=$(row post-long)
assert_equals "post-long body length" "${#LONG_STRING}" "$(jq -r '.body | length' <<<"$long")"
assert_equals "post-long body content" "$LONG_STRING" "$(jq -r '.body' <<<"$long")"

unicode=$(row post-unicode)
assert_equals "post-unicode body survived byte-for-byte" "$UNICODE_STRING" "$(jq -r '.body' <<<"$unicode")"

perms=$(row post-perms)
assert_equals "post-perms kept its row permissions" \
    'read("any"),update("users")' \
    "$(jq -r '.["$permissions"] | sort | join(",")' <<<"$perms")"

assert_equals "posts table kept its permissions" \
    'create("users"),read("any")' \
    "$(api GET "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}" | jq -r '.["$permissions"] | sort | join(",")')"

assert_equals "row count on posts" "4" \
    "$(api GET "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows" | jq -r '.total')"

# --- user and file survived --------------------------------------------------

echo "==> User and file"
user=$(api GET "/users/${USER_ID}")
assert_equals "user email" "gate-user@example.com" "$(jq -r '.email' <<<"$user")"
assert_equals "user name with unicode" "$UNICODE_STRING" "$(jq -r '.name' <<<"$user")"

file=$(api GET "/storage/buckets/${BUCKET_ID}/files/${FILE_ID}")
assert_equals "file still listed" "$FILE_ID" "$(jq -r '.["$id"]' <<<"$file")"

downloaded=$(curl --silent --show-error --fail-with-body --max-time 60 \
    -H "x-appwrite-project: ${PROJECT_ID}" \
    -H "x-appwrite-key: ${API_KEY}" \
    "${ENDPOINT}/storage/buckets/${BUCKET_ID}/files/${FILE_ID}/download")
assert_equals "file contents" "$UNICODE_STRING" "$(printf '%s' "$downloaded" | head -1)"

# --- the upgraded install still takes writes ---------------------------------

echo "==> Writes after upgrade"
written=$(api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows" "$(jq -nc \
    --arg body "$UNICODE_STRING" \
    '{rowId: "post-after-upgrade", data: {title: "Written after upgrade", body: $body}}')")
assert_equals "new row readable" "Written after upgrade" "$(jq -r '.title' <<<"$written")"

api PATCH "/users/${USER_ID}/name" '{"name":"Renamed after upgrade"}' >/dev/null
assert_equals "user update applied" "Renamed after upgrade" \
    "$(api GET "/users/${USER_ID}" | jq -r '.name')"

new_author=$(api POST "/tablesdb/${DATABASE_ID}/tables/${AUTHORS_TABLE}/rows" \
    '{"rowId":"author-after-upgrade","data":{"name":"Grace Hopper"}}' | jq -r '.["$id"]')
linked=$(api PATCH "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows/post-after-upgrade" "$(jq -nc \
    --arg author "$new_author" '{data: {author: $author}}')")
assert_equals "relationship writable after upgrade" "$new_author" \
    "$(jq -r 'if (.author | type) == "object" then .author["$id"] else .author end' <<<"$linked")"

# --- result ------------------------------------------------------------------

if [ "$FAILURES" -gt 0 ]; then
    fail "${FAILURES} assertion(s) failed upgrading ${SEEDED_ON} -> ${UPGRADED_TO}."
fi

echo "All assertions passed upgrading ${SEEDED_ON} -> ${UPGRADED_TO}."
