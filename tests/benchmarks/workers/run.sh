#!/usr/bin/env bash
# Orchestrate worker-mode benchmarks: combined, separate, and optionally main.
#
# Usage:
#   ./tests/benchmarks/workers/run.sh              # combined + separate on current branch
#   ./tests/benchmarks/workers/run.sh --with-main  # also benchmark main (worktree + stack swap)
#   ./tests/benchmarks/workers/run.sh combined     # one mode only
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

RESULTS_DIR="$ROOT/tests/benchmarks/workers/results"
mkdir -p "$RESULTS_DIR"
ENDPOINT="${APPWRITE_ENDPOINT:-http://localhost/v1}"
USERS="${BENCH_USERS:-50}"
ATTRS="${BENCH_ATTRIBUTES:-20}"
DOCS="${BENCH_DOCUMENTS:-200}"
IDLE="${BENCH_IDLE_SECONDS:-8}"
WITH_MAIN=0
MODES=()

for arg in "$@"; do
  case "$arg" in
    --with-main) WITH_MAIN=1 ;;
    combined|separate|main) MODES+=("$arg") ;;
    *) echo "Unknown arg: $arg" >&2; exit 1 ;;
  esac
done

if [[ ${#MODES[@]} -eq 0 ]]; then
  MODES=(combined separate)
fi
if [[ $WITH_MAIN -eq 1 ]]; then
  MODES+=(main)
fi

run_bench() {
  local label="$1"
  local out="$RESULTS_DIR/${label}.json"
  echo "==> Running bench label=${label}"
  php "$ROOT/tests/benchmarks/workers/bench.php" \
    --endpoint="$ENDPOINT" \
    --label="$label" \
    --output="$out" \
    --users="$USERS" \
    --attributes="$ATTRS" \
    --documents="$DOCS" \
    --idle-seconds="$IDLE"
}

wait_healthy() {
  local tries=60
  for ((i=1; i<=tries; i++)); do
    if curl -sf -o /dev/null "$ENDPOINT/health/version" || curl -sf -o /dev/null -H 'X-Appwrite-Project: console' "$ENDPOINT/locale"; then
      echo "API reachable"
      return 0
    fi
    # version may require auth; locale is public on many builds
    code="$(curl -s -o /dev/null -w '%{http_code}' "$ENDPOINT/locale" || true)"
    if [[ "$code" == "200" ]]; then
      echo "API reachable (locale)"
      return 0
    fi
    sleep 2
  done
  echo "API did not become ready" >&2
  return 1
}

COMPOSE_BASE=(docker compose -f docker-compose.yml -f docker-compose.override.yml)
COMPOSE_SEPARATE=(docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.separate.yml --profile separate)

ensure_combined() {
  echo "==> Switching to COMBINED worker/scheduler"
  "${COMPOSE_SEPARATE[@]}" stop \
    appwrite-worker-webhooks appwrite-worker-deletes appwrite-worker-databases \
    appwrite-worker-builds appwrite-worker-jobs appwrite-worker-screenshots \
    appwrite-worker-certificates appwrite-worker-functions appwrite-worker-mails \
    appwrite-worker-notifications appwrite-worker-messaging appwrite-worker-migrations \
    appwrite-task-scheduler-functions appwrite-task-scheduler-executions \
    appwrite-task-scheduler-messages 2>/dev/null || true

  "${COMPOSE_BASE[@]}" up -d --no-build --no-deps appwrite-worker appwrite-task-scheduler
  "${COMPOSE_BASE[@]}" up -d --no-build appwrite redis postgresql mongodb appwrite-embedding traefik 2>/dev/null || true
  sleep 8
  wait_healthy
}

ensure_separate() {
  echo "==> Switching to SEPARATE per-queue workers/schedulers"
  "${COMPOSE_BASE[@]}" stop appwrite-worker appwrite-task-scheduler 2>/dev/null || true
  # --no-deps avoids stopping redis/db while attaching profile services
  "${COMPOSE_SEPARATE[@]}" up -d --no-build --no-deps \
    appwrite-worker-webhooks appwrite-worker-deletes appwrite-worker-databases \
    appwrite-worker-builds appwrite-worker-jobs appwrite-worker-screenshots \
    appwrite-worker-certificates appwrite-worker-functions appwrite-worker-mails \
    appwrite-worker-notifications appwrite-worker-messaging appwrite-worker-migrations \
    appwrite-task-scheduler-functions appwrite-task-scheduler-executions \
    appwrite-task-scheduler-messages
  "${COMPOSE_BASE[@]}" up -d --no-build appwrite redis postgresql mongodb appwrite-embedding traefik 2>/dev/null || true
  sleep 10
  wait_healthy
}

run_main_branch() {
  local worktree="/tmp/appwrite-bench-main"
  local branch_ref="main"
  echo "==> Preparing main branch worktree at ${worktree}"

  # Stop feature-branch stack but keep volumes (shared schema)
  COMPOSE_PROJECT_NAME=appwrite docker compose -f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose.override.yml" down --remove-orphans || true

  # Drop name collisions from prior separate profile
  docker ps -aq --filter name='^/appwrite-worker-' --filter name='^/appwrite-task-scheduler' 2>/dev/null | xargs -r docker rm -f 2>/dev/null || true
  docker ps -aq --filter name='appwrite-worker-' 2>/dev/null | xargs -r docker rm -f 2>/dev/null || true
  docker ps -aq --filter name='appwrite-task-scheduler' 2>/dev/null | xargs -r docker rm -f 2>/dev/null || true

  if [[ -d "$worktree" ]]; then
    git -C "$worktree" fetch origin "$branch_ref" 2>/dev/null || true
    git -C "$worktree" checkout "$branch_ref"
    git -C "$worktree" pull --ff-only origin "$branch_ref" 2>/dev/null || true
  else
    git worktree add "$worktree" "$branch_ref"
  fi

  cp -f "$ROOT/.env" "$worktree/.env"
  if [[ -f "$ROOT/docker-compose.override.yml" ]]; then
    # Drop the temporary Postgres.php bind-mount — main has its own vendor tree.
    grep -v 'utopia-php/database/src/Database/Adapter/Postgres.php' \
      "$ROOT/docker-compose.override.yml" > "$worktree/docker-compose.override.yml" || \
      cp -f "$ROOT/docker-compose.override.yml" "$worktree/docker-compose.override.yml"
  fi

  pushd "$worktree" >/dev/null
  export COMPOSE_PROJECT_NAME=appwrite
  echo "==> Building and starting main stack (separate workers are default on main)"
  docker compose up -d --build
  sleep 25
  wait_healthy
  php "$ROOT/tests/benchmarks/workers/bench.php" \
    --endpoint="$ENDPOINT" \
    --label="main-separate" \
    --output="$RESULTS_DIR/main-separate.json" \
    --users="$USERS" \
    --attributes="$ATTRS" \
    --documents="$DOCS" \
    --idle-seconds="$IDLE"
  docker compose down --remove-orphans || true
  popd >/dev/null

  echo "==> Restoring feature-branch combined stack"
  cd "$ROOT"
  export COMPOSE_PROJECT_NAME=appwrite
  docker compose up -d --no-build
  sleep 15
  wait_healthy
}

summarize() {
  echo
  echo "==== Results ===="
  for f in "$RESULTS_DIR"/*.json; do
    [[ -f "$f" ]] || continue
    php -r '
      $r = json_decode(file_get_contents($argv[1]), true);
      printf("%-22s containers=%-2d idle=%6.1fMiB peak=%6.1fMiB e2e=%7.0fms attrs=%6.0fms docs/s=%.1f users/s=%.1f (%s@%s)\n",
        $r["label"],
        $r["containers"]["count"],
        $r["containers"]["idleAvgMemMiB"],
        $r["containers"]["peakMemMiB"],
        $r["performance"]["e2eWorkerPathMs"],
        $r["performance"]["attributesTotalMs"],
        $r["performance"]["documentsCreatePerSec"],
        $r["performance"]["usersCreatePerSec"],
        $r["branch"],
        $r["commit"]
      );
    ' "$f"
  done
}

for mode in "${MODES[@]}"; do
  case "$mode" in
    combined)
      ensure_combined
      run_bench "branch-combined"
      ;;
    separate)
      ensure_separate
      run_bench "branch-separate"
      ;;
    main)
      run_main_branch
      ;;
  esac
done

# Leave stack in combined mode when we ran both branch modes
if [[ " ${MODES[*]} " == *" combined "* && " ${MODES[*]} " == *" separate "* ]]; then
  ensure_combined || true
fi

summarize
echo "JSON results in $RESULTS_DIR"
