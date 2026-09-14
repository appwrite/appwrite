#!/usr/bin/env bash
# Seeds an Appwrite install with the data shapes the upgrade gate cares about:
# empty values, long strings, unicode, relationships and mixed permissions.
# Run against the PREVIOUS stable, before switching images.
#
#   ENDPOINT=http://localhost:8080/v1 MANIFEST=upgrade-seed.json ./upgrade-seed.sh
#
# Writes every created ID to MANIFEST so upgrade-verify.sh can read the same
# resources back after the migration.

set -euo pipefail

# shellcheck source=.github/scripts/upgrade-lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/upgrade-lib.sh"

ROOT_EMAIL="upgrade-gate@example.com"
ROOT_PASSWORD="upgrade-gate-password-1"
DATABASE_ID="gate"
AUTHORS_TABLE="authors"
POSTS_TABLE="posts"
BUCKET_ID="gate"
FILE_ID="gate-file"

# The shapes a migration is most likely to mangle.
EMPTY_STRING=""
LONG_STRING=$(printf 'l%.0s' $(seq 1 4000))
UNICODE_STRING="ünïcødé — 日本語 — 🚂 — <script>alert(1)</script> — O'Brien \"quoted\""

wait_for_appwrite
echo "Seeding $(server_version) at ${ENDPOINT}"

# --- console: root account, organization, project, API key -------------------

echo "==> Console account and project"
rm -f "$COOKIE_JAR"

# On a fresh install the first account becomes root.
console POST /account "$(jq -nc \
    --arg email "$ROOT_EMAIL" \
    --arg password "$ROOT_PASSWORD" \
    '{userId: "unique()", email: $email, password: $password, name: "Upgrade Gate"}')" \
    >/dev/null

console POST /account/sessions/email "$(jq -nc \
    --arg email "$ROOT_EMAIL" \
    --arg password "$ROOT_PASSWORD" \
    '{email: $email, password: $password}')" >/dev/null

TEAM_ID=$(console POST /teams '{"teamId":"unique()","name":"Upgrade Gate"}' | jq -r '.["$id"]')
[ -n "$TEAM_ID" ] && [ "$TEAM_ID" != "null" ] || fail "Could not create the organization."

PROJECT_ID=$(console POST /projects "$(jq -nc \
    --arg teamId "$TEAM_ID" \
    '{projectId: "upgrade-gate", name: "Upgrade Gate", teamId: $teamId}')" | jq -r '.["$id"]')
[ -n "$PROJECT_ID" ] && [ "$PROJECT_ID" != "null" ] || fail "Could not create the project."
export PROJECT_ID

API_KEY=$(console POST "/projects/${PROJECT_ID}/keys" "$(jq -nc \
    '{name: "upgrade-gate", scopes: ["users.read","users.write","tables.read","tables.write","databases.read","databases.write","rows.read","rows.write","documents.read","documents.write","collections.read","collections.write","files.read","files.write","buckets.read","buckets.write"]}')" \
    | jq -r '.secret')
[ -n "$API_KEY" ] && [ "$API_KEY" != "null" ] || fail "Could not create an API key."
export API_KEY

# --- tables, columns, relationship -------------------------------------------

echo "==> Database, tables and columns"
api POST /tablesdb "$(jq -nc --arg id "$DATABASE_ID" '{databaseId: $id, name: "Gate"}')" >/dev/null

api POST "/tablesdb/${DATABASE_ID}/tables" "$(jq -nc \
    --arg id "$AUTHORS_TABLE" \
    '{tableId: $id, name: "Authors", permissions: ["read(\"any\")"], rowSecurity: false}')" >/dev/null

api POST "/tablesdb/${DATABASE_ID}/tables" "$(jq -nc \
    --arg id "$POSTS_TABLE" \
    '{tableId: $id, name: "Posts", permissions: ["read(\"any\")","create(\"users\")"], rowSecurity: true}')" >/dev/null

api POST "/tablesdb/${DATABASE_ID}/tables/${AUTHORS_TABLE}/columns/string" \
    '{"key":"name","size":255,"required":true}' >/dev/null
wait_for_columns "$DATABASE_ID" "$AUTHORS_TABLE"

api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/columns/string" \
    '{"key":"title","size":255,"required":true}' >/dev/null
api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/columns/string" \
    '{"key":"body","size":5000,"required":false}' >/dev/null
api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/columns/string" \
    '{"key":"tag","size":64,"required":false,"default":"untagged"}' >/dev/null
api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/columns/relationship" "$(jq -nc \
    --arg related "$AUTHORS_TABLE" \
    '{relatedTableId: $related, type: "manyToOne", twoWay: false, key: "author", onDelete: "setNull"}')" >/dev/null
wait_for_columns "$DATABASE_ID" "$POSTS_TABLE"

# --- rows --------------------------------------------------------------------

echo "==> Rows"
AUTHOR_ID=$(api POST "/tablesdb/${DATABASE_ID}/tables/${AUTHORS_TABLE}/rows" \
    '{"rowId":"author-1","data":{"name":"Ada Lovelace"}}' | jq -r '.["$id"]')

api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows" "$(jq -nc \
    --arg author "$AUTHOR_ID" \
    --arg body "$EMPTY_STRING" \
    '{rowId: "post-empty", data: {title: "Empty body", body: $body, author: $author}}')" >/dev/null

api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows" "$(jq -nc \
    --arg body "$LONG_STRING" \
    '{rowId: "post-long", data: {title: "Long body", body: $body}}')" >/dev/null

api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows" "$(jq -nc \
    --arg body "$UNICODE_STRING" \
    '{rowId: "post-unicode", data: {title: "Unicode body", body: $body}}')" >/dev/null

# Row-level permissions, deliberately different from the table's.
api POST "/tablesdb/${DATABASE_ID}/tables/${POSTS_TABLE}/rows" "$(jq -nc \
    '{rowId: "post-perms", data: {title: "Mixed permissions", body: "kept"}, permissions: ["read(\"any\")","update(\"users\")"]}')" >/dev/null

# --- users, storage ----------------------------------------------------------

echo "==> User and file"
USER_ID=$(api POST /users "$(jq -nc \
    --arg name "$UNICODE_STRING" \
    '{userId: "gate-user", email: "gate-user@example.com", password: "gate-user-password-1", name: $name}')" \
    | jq -r '.["$id"]')

api POST /storage/buckets "$(jq -nc \
    --arg id "$BUCKET_ID" \
    '{bucketId: $id, name: "Gate", permissions: ["read(\"any\")"], fileSecurity: false}')" >/dev/null

FILE_CONTENT=$(dirname "$MANIFEST")/gate-file.txt
printf '%s\n' "$UNICODE_STRING" > "$FILE_CONTENT"

curl --silent --show-error --fail-with-body --max-time 60 \
    -X POST \
    -H "x-appwrite-project: ${PROJECT_ID}" \
    -H "x-appwrite-key: ${API_KEY}" \
    -F "fileId=${FILE_ID}" \
    -F "file=@${FILE_CONTENT}" \
    "${ENDPOINT}/storage/buckets/${BUCKET_ID}/files" >/dev/null

# --- manifest ----------------------------------------------------------------

jq -nc \
    --arg version "$(server_version)" \
    --arg projectId "$PROJECT_ID" \
    --arg apiKey "$API_KEY" \
    --arg teamId "$TEAM_ID" \
    --arg databaseId "$DATABASE_ID" \
    --arg authorsTable "$AUTHORS_TABLE" \
    --arg postsTable "$POSTS_TABLE" \
    --arg authorId "$AUTHOR_ID" \
    --arg userId "$USER_ID" \
    --arg bucketId "$BUCKET_ID" \
    --arg fileId "$FILE_ID" \
    --arg longString "$LONG_STRING" \
    --arg unicodeString "$UNICODE_STRING" \
    '{seededOn: $version, projectId: $projectId, apiKey: $apiKey, teamId: $teamId,
      databaseId: $databaseId, authorsTable: $authorsTable, postsTable: $postsTable,
      authorId: $authorId, userId: $userId, bucketId: $bucketId, fileId: $fileId,
      longString: $longString, unicodeString: $unicodeString}' > "$MANIFEST"

echo "Seeded from $(server_version); manifest at ${MANIFEST}"
