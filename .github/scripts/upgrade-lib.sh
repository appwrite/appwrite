#!/usr/bin/env bash
# Shared helpers for the upgrade gate scripts. Sourced, not executed.
#
# The upgrade gate talks to Appwrite the way a self-hoster does: over the public
# HTTP API, with no access to the container internals. That is the point -- it
# proves the data survives a version change through the surface users depend on.

set -euo pipefail

: "${ENDPOINT:?ENDPOINT must be set, e.g. http://localhost:8080/v1}"

MANIFEST="${MANIFEST:-upgrade-seed.json}"
COOKIE_JAR="${COOKIE_JAR:-$(dirname "$MANIFEST")/upgrade-cookies.txt}"

fail() {
    echo "::error::$*" >&2
    exit 1
}

# Waits for the stack to answer on the public port. A fresh install spends a
# while on migrations and worker boot, so this is generous.
wait_for_appwrite() {
    local attempts="${1:-90}" i

    for ((i = 1; i <= attempts; i++)); do
        if curl --silent --fail --max-time 5 "${ENDPOINT}/health/version" >/dev/null 2>&1; then
            echo "Appwrite answering at ${ENDPOINT} after ${i}s."
            return 0
        fi
        sleep 1
    done

    fail "Appwrite did not answer at ${ENDPOINT}/health/version within ${attempts}s."
}

server_version() {
    curl --silent --fail --max-time 10 "${ENDPOINT}/health/version" | jq -r '.version'
}

# Console calls: cookie session, browser origin. Used to create the org and
# project a self-hoster starts with.
console() {
    local method="$1" path="$2" body="${3:-}"
    local args=(
        --silent --show-error --fail-with-body --max-time 30
        -X "$method"
        -H 'content-type: application/json'
        -H 'origin: http://localhost'
        -H 'x-appwrite-project: console'
        -c "$COOKIE_JAR" -b "$COOKIE_JAR"
    )
    [ -n "$body" ] && args+=(-d "$body")

    curl "${args[@]}" "${ENDPOINT}${path}"
}

# Project calls: API key, the way a server SDK talks to Appwrite.
api() {
    local method="$1" path="$2" body="${3:-}"
    local args=(
        --silent --show-error --fail-with-body --max-time 60
        -X "$method"
        -H 'content-type: application/json'
        -H "x-appwrite-project: ${PROJECT_ID:?PROJECT_ID not set}"
        -H "x-appwrite-key: ${API_KEY:?API_KEY not set}"
    )
    [ -n "$body" ] && args+=(-d "$body")

    curl "${args[@]}" "${ENDPOINT}${path}"
}

# Columns are processed asynchronously, so rows cannot be written until every
# column on the table reports available.
wait_for_columns() {
    local database="$1" table="$2" attempts="${3:-60}" i statuses

    for ((i = 1; i <= attempts; i++)); do
        statuses=$(api GET "/tablesdb/${database}/tables/${table}/columns" \
            | jq -r '.columns[].status' | sort -u | tr '\n' ' ')

        case "$statuses" in
            'available ') return 0 ;;
            *failed*) fail "Column processing failed on ${table}: ${statuses}" ;;
        esac
        sleep 1
    done

    fail "Columns on ${table} did not become available within ${attempts}s (last: ${statuses:-none})."
}

manifest_get() {
    jq -r "$1" "$MANIFEST"
}

# Asserts on values read back through the API. Prints every check so a failed
# upgrade job reads as a list of what survived and what did not.
assert_equals() {
    local what="$1" expected="$2" actual="$3"

    if [ "$expected" = "$actual" ]; then
        echo "  ok    ${what}"
        return 0
    fi

    echo "  FAIL  ${what}"
    echo "        expected: ${expected}"
    echo "        actual:   ${actual}"
    FAILURES=$((${FAILURES:-0} + 1))
}
