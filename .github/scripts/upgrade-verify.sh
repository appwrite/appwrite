#!/usr/bin/env bash
# Asserts the seeded data came through the migration unchanged. Run against the
# TARGET version, after migrate.
#
#   ENDPOINT=http://localhost:8080/v1 ./upgrade-verify.sh
#   ENDPOINT=http://localhost:8080/v1 ./upgrade-verify.sh --write
#
# The default is read-only, so it can run after every migrate. --write also
# proves the upgraded install still takes writes; pass it once, on the last run,
# since the rows it creates would show up in the next comparison.

set -euo pipefail

# shellcheck source=.github/scripts/upgrade-lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/upgrade-lib.sh"

[ -f "$SNAPSHOT" ] || fail "No snapshot at ${SNAPSHOT}; did upgrade-seed.sh run?"

PROJECT=$(jq -r '.project' "$MANIFEST"); export PROJECT
KEY=$(jq -r '.key' "$MANIFEST"); export KEY
SEEDED_ON=$(jq -r '.seededOn' "$MANIFEST")

wait_for_appwrite
UPGRADED_TO=$(version)

echo "Verifying ${SEEDED_ON} -> ${UPGRADED_TO}"

if [ "$SEEDED_ON" = "$UPGRADED_TO" ]; then
    fail "Still reporting ${UPGRADED_TO}; the image did not change, so this run proves nothing."
fi

# The project, its key and its scopes have to survive before anything else can
# be checked.
api GET /users >/dev/null 2>&1 || fail "The seeded API key no longer authenticates against project ${PROJECT}."

# --- nothing the user put in changed -----------------------------------------

if ! diff --unified=1 --label "seeded on ${SEEDED_ON}" --label "read back on ${UPGRADED_TO}" \
    "$SNAPSHOT" <(snapshot); then
    fail 'Seeded data changed across the upgrade; see the diff above.'
fi

echo 'ok: rows, permissions, relationship, user and file all read back unchanged'

if [ "${1:-}" != '--write' ]; then
    exit 0
fi

# --- the upgraded install still takes writes ---------------------------------

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
echo "All checks passed upgrading ${SEEDED_ON} -> ${UPGRADED_TO}."
