#!/usr/bin/env bash
# Seeds an install with the data shapes the upgrade gate cares about, then
# records a snapshot of them. Run against the PREVIOUS stable, before switching
# images.
#
#   ENDPOINT=http://localhost:8080/v1 ./upgrade-seed.sh

set -euo pipefail

# shellcheck source=.github/scripts/upgrade-lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/upgrade-lib.sh"

wait_for_appwrite
echo "Seeding $(version) at ${ENDPOINT}"

# --- console: root account, organization, project, key -----------------------

rm -f "$COOKIE_JAR"

# The first account on a fresh install becomes root.
console POST /account \
    '{"userId":"unique()","email":"gate@example.com","password":"upgrade-gate-1","name":"Gate"}' >/dev/null
console POST /account/sessions/email \
    '{"email":"gate@example.com","password":"upgrade-gate-1"}' >/dev/null

TEAM=$(console POST /teams '{"teamId":"unique()","name":"Gate"}' | jq -r '.["$id"]')
PROJECT=$(console POST /projects "{\"projectId\":\"gate\",\"name\":\"Gate\",\"teamId\":\"${TEAM}\"}" | jq -r '.["$id"]')
export PROJECT

KEY=$(console POST "/projects/${PROJECT}/keys" \
    '{"name":"gate","scopes":["users.read","users.write","tables.read","tables.write","rows.read","rows.write","files.read","files.write","buckets.read","buckets.write"]}' \
    | jq -r '.secret')
export KEY

[ "$PROJECT" = 'gate' ] && [ -n "$KEY" ] && [ "$KEY" != 'null' ] || fail 'Could not create a project and key.'

# --- tables ------------------------------------------------------------------

echo '==> Tables'
api POST /tablesdb "{\"databaseId\":\"${DATABASE}\",\"name\":\"Gate\"}" >/dev/null

api POST "/tablesdb/${DATABASE}/tables" "$(jq -nc --arg id "$AUTHORS" '{
    tableId: $id, name: "Authors", permissions: ["read(\"any\")"], rowSecurity: false,
    columns: [{key: "name", type: "string", size: 255, required: true}]
}')" >/dev/null

api POST "/tablesdb/${DATABASE}/tables" "$(jq -nc --arg id "$POSTS" '{
    tableId: $id, name: "Posts", permissions: ["read(\"any\")", "create(\"users\")"], rowSecurity: true,
    columns: [
        {key: "title", type: "string", size: 255, required: true},
        {key: "body",  type: "string", size: 5000, required: false},
        {key: "tag",   type: "string", size: 64,  required: false, default: "untagged"}
    ]
}')" >/dev/null

# Relationships are not expressible in the inline columns array.
api POST "/tablesdb/${DATABASE}/tables/${POSTS}/columns/relationship" "$(jq -nc --arg related "$AUTHORS" '{
    relatedTableId: $related, type: "manyToOne", twoWay: false, key: "author", onDelete: "setNull"
}')" >/dev/null

wait_for_columns "$AUTHORS"
wait_for_columns "$POSTS"

# --- rows --------------------------------------------------------------------

echo '==> Rows'
api POST "/tablesdb/${DATABASE}/tables/${AUTHORS}/rows" \
    "{\"rowId\":\"${AUTHOR}\",\"data\":{\"name\":\"Ada Lovelace\"}}" >/dev/null

# An empty string, a relationship, and a column default left to apply.
api POST "/tablesdb/${DATABASE}/tables/${POSTS}/rows" "$(jq -nc --arg author "$AUTHOR" '{
    rowId: "post-empty", data: {title: "Empty body", body: "", author: $author}
}')" >/dev/null

api POST "/tablesdb/${DATABASE}/tables/${POSTS}/rows" "$(jq -nc --arg body "$LONG_VALUE" '{
    rowId: "post-long", data: {title: "Long body", body: $body}
}')" >/dev/null

api POST "/tablesdb/${DATABASE}/tables/${POSTS}/rows" "$(jq -nc --arg body "$UNICODE_VALUE" '{
    rowId: "post-unicode", data: {title: "Unicode body", body: $body}
}')" >/dev/null

# Row permissions deliberately different from the table's.
api POST "/tablesdb/${DATABASE}/tables/${POSTS}/rows" '{
    "rowId": "post-perms", "data": {"title": "Mixed permissions", "body": "kept"},
    "permissions": ["read(\"any\")", "update(\"users\")"]
}' >/dev/null

# --- user and file -----------------------------------------------------------

echo '==> User and file'
api POST /users "$(jq -nc --arg id "$USER_ID" --arg name "$UNICODE_VALUE" '{
    userId: $id, email: "gate-user@example.com", password: "gate-user-1", name: $name
}')" >/dev/null

api POST /storage/buckets "$(jq -nc --arg id "$BUCKET" '{
    bucketId: $id, name: "Gate", permissions: ["read(\"any\")"], fileSecurity: false
}')" >/dev/null

printf '%s\n' "$UNICODE_VALUE" > "${STATE}/gate-file.txt"
curl --silent --show-error --fail-with-body --max-time 60 \
    -X POST \
    -H "x-appwrite-project: ${PROJECT}" \
    -H "x-appwrite-key: ${KEY}" \
    -F "fileId=${FILE_ID}" \
    -F "file=@${STATE}/gate-file.txt" \
    "${ENDPOINT}/storage/buckets/${BUCKET}/files" >/dev/null

# --- record ------------------------------------------------------------------

jq -nc --arg version "$(version)" --arg project "$PROJECT" --arg key "$KEY" \
    '{seededOn: $version, project: $project, key: $key}' > "$MANIFEST"
snapshot > "$SNAPSHOT"

echo "Seeded from $(version); snapshot at ${SNAPSHOT}"
