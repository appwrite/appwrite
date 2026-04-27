#!/usr/bin/env bash
#
# Manual smoke test for the distributed-lock pilot on `updateProjectService`.
#
# Fires N concurrent PATCH /project/services/:serviceId requests against the
# same project, each toggling a different service to "enabled=true". Then
# refetches the project and counts how many of the targeted services actually
# persisted.
#
# Usage:
#   APPWRITE_ENDPOINT=http://localhost \
#   APPWRITE_PROJECT_ID=<id> \
#   APPWRITE_API_KEY=<key with project.write scope> \
#   ./dev/test-distributed-lock.sh
#
# Required scopes on the API key:
#   - project.write       (to toggle services)
#   - projects.read       (to refetch project state via GET /v1/projects/:id)
#
# To prove the lock fixes the bug, run twice:
#   1. With `_APP_LOCKING_ENABLED=enabled` (default): expect successes == enabled
#   2. With `_APP_LOCKING_ENABLED=disabled`         : expect successes >  enabled (lost updates)
#
# Set `_APP_LOCKING_ENABLED` in `.env` and `docker compose up -d --force-recreate`
# between runs. Use `--parallelism N` to tune the concurrency level.

set -eu

# --- Configuration ---------------------------------------------------------

ENDPOINT="${APPWRITE_ENDPOINT:?set APPWRITE_ENDPOINT, e.g. http://localhost}"
PROJECT_ID="${APPWRITE_PROJECT_ID:?set APPWRITE_PROJECT_ID}"
API_KEY="${APPWRITE_API_KEY:?set APPWRITE_API_KEY}"
PARALLELISM="${PARALLELISM:-5}"

# Services to toggle concurrently — must be in the optional-services list of
# the project. These are the same set used by the e2e ServicesBase trait.
SERVICES=(teams storage functions sites messaging)

# Trim or extend SERVICES to match PARALLELISM.
SERVICES=("${SERVICES[@]:0:$PARALLELISM}")

if [ "${#SERVICES[@]}" -lt 2 ]; then
    echo "ERROR: PARALLELISM must be >= 2 to detect contention" >&2
    exit 1
fi

# --- Helpers ---------------------------------------------------------------

curl_appwrite() {
    local method="$1"
    local path="$2"
    shift 2
    curl -sS -o /tmp/lock-smoke-body.$$ -w '%{http_code}' \
        -X "$method" \
        -H "Content-Type: application/json" \
        -H "X-Appwrite-Project: $PROJECT_ID" \
        -H "X-Appwrite-Key: $API_KEY" \
        "$ENDPOINT/v1$path" \
        "$@"
}

toggle_service() {
    local service="$1"
    local enabled="$2"
    local code
    code=$(curl_appwrite PATCH "/project/services/$service" \
        -d "{\"enabled\": $enabled}")
    echo "$code"
}

get_project_state() {
    curl -sS \
        -H "Content-Type: application/json" \
        -H "X-Appwrite-Project: console" \
        -H "X-Appwrite-Key: $API_KEY" \
        "$ENDPOINT/v1/projects/$PROJECT_ID"
}

# --- Run -------------------------------------------------------------------

echo "==> Distributed-lock smoke test"
echo "    endpoint:    $ENDPOINT"
echo "    project:     $PROJECT_ID"
echo "    parallelism: $PARALLELISM"
echo "    services:    ${SERVICES[*]}"
echo

# 1. Baseline — disable all targeted services sequentially.
echo "==> Baseline: disabling ${SERVICES[*]}"
for svc in "${SERVICES[@]}"; do
    code=$(toggle_service "$svc" false)
    if [ "$code" != "200" ]; then
        echo "    WARN: baseline disable of $svc returned $code (expected 200)"
    fi
done

# 2. Fire concurrent toggles to enabled=true. Capture each child's HTTP status.
echo
echo "==> Firing ${#SERVICES[@]} concurrent toggle requests..."
RESULTS_FILE=$(mktemp -t lock-smoke-results.XXXXXX)
for svc in "${SERVICES[@]}"; do
    (
        code=$(toggle_service "$svc" true)
        printf '%s %s\n' "$svc" "$code" >> "$RESULTS_FILE"
    ) &
done
wait

# 3. Tally responses.
SUCCESS_COUNT=$(awk '$2 == 200' "$RESULTS_FILE" | wc -l | tr -d ' ')
CONFLICT_COUNT=$(awk '$2 == 409' "$RESULTS_FILE" | wc -l | tr -d ' ')
OTHER_COUNT=$(awk '$2 != 200 && $2 != 409' "$RESULTS_FILE" | wc -l | tr -d ' ')

echo
echo "==> Child responses:"
sort "$RESULTS_FILE"
echo
echo "    successes (200): $SUCCESS_COUNT"
echo "    conflicts (409): $CONFLICT_COUNT"
echo "    other:           $OTHER_COUNT"
rm -f "$RESULTS_FILE"

# 4. Refetch project; count how many targeted services are enabled.
PROJECT_JSON=$(get_project_state)
ENABLED_COUNT=0
for svc in "${SERVICES[@]}"; do
    # Capitalize first letter to form serviceStatusFor<Svc> key.
    Cap="$(echo "$svc" | awk '{print toupper(substr($1,1,1)) substr($1,2)}')"
    val=$(echo "$PROJECT_JSON" | sed -nE "s/.*\"serviceStatusFor${Cap}\":[[:space:]]*(true|false).*/\1/p" | head -n1)
    if [ "$val" = "true" ]; then
        ENABLED_COUNT=$((ENABLED_COUNT + 1))
    fi
done

echo "    enabled in project state: $ENABLED_COUNT"
echo

# 5. Verdict.
if [ "$SUCCESS_COUNT" -eq "$ENABLED_COUNT" ]; then
    echo "PASS: every successful toggle persisted (no lost updates)."
    EXIT=0
else
    echo "FAIL: lost updates detected. successes=$SUCCESS_COUNT enabled=$ENABLED_COUNT"
    echo "      Locking is either disabled or not effective on this endpoint."
    EXIT=1
fi

# 6. Cleanup — re-enable all targeted services.
echo
echo "==> Cleanup: re-enabling ${SERVICES[*]}"
for svc in "${SERVICES[@]}"; do
    toggle_service "$svc" true >/dev/null || true
done

rm -f /tmp/lock-smoke-body.$$
exit "$EXIT"
