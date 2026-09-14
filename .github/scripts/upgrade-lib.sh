#!/usr/bin/env bash
# Shared ground for the upgrade gate. Sourced, not executed.
#
# The gate talks to Appwrite the way a self-hoster does -- over the public HTTP
# API, with no access to container internals -- because that is the surface the
# data has to survive a version change on.

# The resource names and value shapes below are read by the scripts that source
# this file, so shellcheck cannot see their use when linting it on its own.
# shellcheck disable=SC2034

set -euo pipefail

: "${ENDPOINT:?ENDPOINT must be set, e.g. http://localhost:8080/v1}"

MANIFEST="${MANIFEST:-upgrade-seed.json}"
STATE="$(dirname "$MANIFEST")"
COOKIE_JAR="${STATE}/upgrade-cookies.txt"
SNAPSHOT="${STATE}/upgrade-snapshot.json"

# Seeded resources. Fixed IDs so both scripts address the same rows without
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

# A fresh install spends a while on migrations and worker boot.
wait_for_appwrite() {
    local i

    for ((i = 1; i <= 120; i++)); do
        if curl --silent --fail --max-time 5 "${ENDPOINT}/health/version" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    fail "Appwrite did not answer at ${ENDPOINT}/health/version within 120s."
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
            file: { id: $file["$id"], size: $file.sizeOriginal }
        }'
}
