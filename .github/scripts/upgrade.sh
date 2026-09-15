#!/usr/bin/env bash
# The payload of the release gates: seed an install with the data shapes a
# migration is most likely to mangle, then assert they came back unchanged.
#
#   ENDPOINT=http://localhost:8080/v1 upgrade.sh seed            # on the previous stable
#   ENDPOINT=http://localhost:8080/v1 upgrade.sh verify          # on the target, after migrate
#   ENDPOINT=http://localhost:8080/v1 upgrade.sh verify --write  # ... and that writes still work
#
# verify is read-only by default so it can run after every migrate. --write also
# proves the upgraded install takes writes; pass it once, on the last run, since
# the rows it creates would show up in the next comparison.
#
# It talks to Appwrite the way a self-hoster does -- over the public HTTP API,
# with no access to container internals -- because that is the surface the data
# has to survive a version change on.

set -euo pipefail

: "${ENDPOINT:?ENDPOINT must be set, e.g. http://localhost:8080/v1}"

MANIFEST="${MANIFEST:-upgrade-seed.json}"
STATE="$(dirname "$MANIFEST")"
COOKIE_JAR="${STATE}/upgrade-cookies.txt"
SNAPSHOT="${STATE}/upgrade-snapshot.json"

# Seeded resources. Fixed IDs so seed and verify address the same rows without
# passing them around.
DATABASE="gate"
AUTHORS="authors"
POSTS="posts"
BUCKET="gate"
FILE_ID="gate-file"
USER_ID="gate-user"
AUTHOR="author-1"

# The value shapes a migration is most likely to mangle.
LONG_VALUE="$(printf 'l%.0s' {1..4000})"
UNICODE_VALUE="ünïcødé — 日本語 — 🚂 — <script>alert(1)</script> — O'Brien \"quoted\""

fail() {
    echo "::error::$*" >&2
    exit 1
}

# /health/version answers before the platform schema is initialized, so wait for
# the console API to reject an anonymous read instead.
wait_for_appwrite() {
    local i status

    for ((i = 1; i <= 120; i++)); do
        status=$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 5 \
            -H 'x-appwrite-project: console' "${ENDPOINT}/account" || true)

        if [ "$status" = '401' ]; then
            return 0
        fi
        sleep 1
    done

    fail "Appwrite did not serve ${ENDPOINT}/account within 120s (last status: ${status:-none})."
}

version() {
    curl --silent --fail --max-time 10 "${ENDPOINT}/health/version" | jq -r '.version'
}

# Console session, browser origin: how the organization and project get made.
console() {
    local method="$1" path="$2" body="${3:-}"

    curl --silent --show-error --fail-with-body --max-time 30 \
        -X "$method" \
        -H 'content-type: application/json' \
        -H 'origin: http://localhost' \
        -H 'x-appwrite-project: console' \
        -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
        ${body:+-d "$body"} \
        "${ENDPOINT}${path}"
}

# API key: how a server SDK talks to a project.
api() {
    local method="$1" path="$2" body="${3:-}"

    curl --silent --show-error --fail-with-body --max-time 60 \
        -X "$method" \
        -H 'content-type: application/json' \
        -H "x-appwrite-project: ${PROJECT:?PROJECT not set}" \
        -H "x-appwrite-key: ${KEY:?KEY not set}" \
        ${body:+-d "$body"} \
        "${ENDPOINT}${path}"
}

# Columns are processed asynchronously; rows cannot be written until they are all
# available.
wait_for_columns() {
    local table="$1" i statuses

    for ((i = 1; i <= 60; i++)); do
        statuses=$(api GET "/tablesdb/${DATABASE}/tables/${table}/columns" \
            | jq -r '.columns[].status' | sort -u | tr '\n' ' ')

        case "$statuses" in
            'available ') return 0 ;;
            *failed*) fail "Column processing failed on ${table}: ${statuses}" ;;
        esac
        sleep 1
    done

    fail "Columns on ${table} did not become available within 60s (last: ${statuses:-none})."
}

# Everything the gate asserts on, projected to just the fields we seeded and
# sorted for a stable comparison. Projecting rather than dumping whole responses
# is deliberate: an upgrade is allowed to add fields to a model, and only the
# values a user put in have to come back unchanged.
snapshot() {
    jq -nS \
        --argjson rows "$(api GET "/tablesdb/${DATABASE}/tables/${POSTS}/rows")" \
        --argjson table "$(api GET "/tablesdb/${DATABASE}/tables/${POSTS}")" \
        --argjson user "$(api GET "/users/${USER_ID}")" \
        --argjson file "$(api GET "/storage/buckets/${BUCKET}/files/${FILE_ID}")" \
        --arg digest "$(api GET "/storage/buckets/${BUCKET}/files/${FILE_ID}/download" | sha256sum | cut -d' ' -f1)" \
        '{
            rows: [$rows.rows[] | {
                id: .["$id"],
                title: .title,
                body: .body,
                tag: .tag,
                author: (if (.author | type) == "object" then .author["$id"] else .author end),
                permissions: (.["$permissions"] | sort)
            }] | sort_by(.id),
            total: $rows.total,
            table: { permissions: ($table["$permissions"] | sort), rowSecurity: $table.rowSecurity },
            user: { email: $user.email, name: $user.name },
            file: { id: $file["$id"], size: $file.sizeOriginal, digest: $digest }
        }'
}

seed() {
    wait_for_appwrite
    echo "Seeding $(version) at ${ENDPOINT}"

    # --- console: root account, organization, project, key -------------------

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

    # --- tables --------------------------------------------------------------

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

    # --- rows ----------------------------------------------------------------

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

    # --- user and file -------------------------------------------------------

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

    # --- record --------------------------------------------------------------

    jq -nc --arg version "$(version)" --arg project "$PROJECT" --arg key "$KEY" \
        '{seededOn: $version, project: $project, key: $key}' > "$MANIFEST"
    snapshot > "$SNAPSHOT"

    echo "Seeded from $(version); snapshot at ${SNAPSHOT}"
}

verify() {
    [ -f "$SNAPSHOT" ] || fail "No snapshot at ${SNAPSHOT}; did the seed run?"

    PROJECT=$(jq -r '.project' "$MANIFEST"); export PROJECT
    KEY=$(jq -r '.key' "$MANIFEST"); export KEY
    local seeded upgraded author written total
    seeded=$(jq -r '.seededOn' "$MANIFEST")

    wait_for_appwrite
    upgraded=$(version)

    echo "Verifying ${seeded} -> ${upgraded}"

    if [ "$seeded" = "$upgraded" ]; then
        fail "Still reporting ${upgraded}; the image did not change, so this run proves nothing."
    fi

    # The project, its key and its scopes have to survive before anything else
    # can be checked.
    api GET /users >/dev/null 2>&1 || fail "The seeded API key no longer authenticates against project ${PROJECT}."

    # --- nothing the user put in changed -------------------------------------

    if ! diff --unified=1 --label "seeded on ${seeded}" --label "read back on ${upgraded}" \
        "$SNAPSHOT" <(snapshot); then
        fail 'Seeded data changed across the upgrade; see the diff above.'
    fi

    echo 'ok: rows, permissions, relationship, user and file all read back unchanged'

    if [ "${1:-}" != '--write' ]; then
        return 0
    fi

    # --- the upgraded install still takes writes -----------------------------

    # A read-only check passes against a half-migrated schema, so write too.
    api POST "/tablesdb/${DATABASE}/tables/${POSTS}/rows" "$(jq -nc --arg body "$UNICODE_VALUE" '{
        rowId: "post-after-upgrade", data: {title: "Written after upgrade", body: $body}
    }')" >/dev/null

    api PATCH "/users/${USER_ID}/name" '{"name":"Renamed after upgrade"}' >/dev/null

    author=$(api POST "/tablesdb/${DATABASE}/tables/${AUTHORS}/rows" \
        '{"rowId":"author-after-upgrade","data":{"name":"Grace Hopper"}}' | jq -r '.["$id"]')
    api PATCH "/tablesdb/${DATABASE}/tables/${POSTS}/rows/post-after-upgrade" \
        "{\"data\":{\"author\":\"${author}\"}}" >/dev/null

    written=$(snapshot)
    total=$(jq -r '.total' <<<"$written")

    [ "$total" = '5' ] || fail "Expected 5 rows after writing one; found ${total}."
    [ "$(jq -r '.user.name' <<<"$written")" = 'Renamed after upgrade' ] || fail 'The user update did not apply.'
    [ "$(jq -r '.rows[] | select(.id == "post-after-upgrade") | .author' <<<"$written")" = "$author" ] \
        || fail 'The relationship written after the upgrade did not stick.'

    echo 'ok: rows, users and relationships are still writable'
    echo "All checks passed upgrading ${seeded} -> ${upgraded}."
}

case "${1:-}" in
    seed) seed ;;
    verify) shift; verify "$@" ;;
    *) fail "Usage: $(basename "$0") seed|verify [--write]" ;;
esac
