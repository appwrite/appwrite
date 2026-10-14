#!/usr/bin/env bash
# Benchmarks one service's API on PHP and on Rust under identical load and
# reports throughput, latency percentiles, CPU time per request and memory,
# per step and per endpoint.
#
# Prerequisites: the dev stack running with the Rust API enabled
#   COMPOSE_PROFILES=postgresql,rust docker compose up -d --wait
# and k6 on PATH. Once the instance has an organization, export
# APPWRITE_ADMIN_EMAIL for an owner of it (see lib.js).
#
#   tests/benchmarks/api/compare.sh users                       # default ladder
#   RATES="100 400" DURATION=120s tests/benchmarks/api/compare.sh users
#   TARGETS=php tests/benchmarks/api/compare.sh teams           # PHP only: Rust does not serve it yet
#   RESULTS=tests/benchmarks/results/users.json tests/benchmarks/api/compare.sh users
#   APPWRITE_BENCHMARK_ERROR_BUDGET=0.25 tests/benchmarks/api/compare.sh users  # a ladder past PHP's capacity
#   ALLOW_BUSY=1 tests/benchmarks/api/compare.sh users          # run even when the machine is busy
#
# Other work on the machine skews every number (a run next to builds and test
# suites measured both runtimes about 3x slower), so the run waits up to
# LOAD_WAIT seconds (default 300) for the 1-minute load average to fall
# below LOAD_MAX (default a quarter of the cores, at least 2), and refuses
# to start otherwise. Each step records the load it started at.
#
# The Markdown report goes to stdout and $OUT/report.md; the same run as JSON
# (per step and per endpoint, with the environment) to $RESULTS, by default
# $OUT/<service>.json. Committed as tests/benchmarks/results/<service>.json,
# it is what `bin/compat appwrite` shows in the docs site's Appwrite migration.
#
# Each step runs the service's k6 workload (tests/benchmarks/api/<service>.js)
# against PHP (port 9501, direct) and Rust (port 9530, direct) while sampling
# the containers. CPU is read from cgroup counters when available (Linux) and
# otherwise integrated from `docker stats`.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SERVICE=${1:?usage: compare.sh <service>}
SCRIPT="$HERE/$SERVICE.js"
[ -f "$SCRIPT" ] || { echo "no benchmark for $SERVICE: $SCRIPT" >&2; exit 2; }
TARGETS=${TARGETS:-"php rust"}
PHP_ENDPOINT=${PHP_ENDPOINT:-http://localhost:9501/v1}
RUST_ENDPOINT=${RUST_ENDPOINT:-http://localhost:9530/v1}
CONTROL_ENDPOINT=${CONTROL_ENDPOINT:-$PHP_ENDPOINT}
RATES=${RATES:-"50 200 500"}
MAX_VUS=${MAX_VUS:-"50 200"}
DURATION=${DURATION:-60s}
HASHING=${HASHING:-false}
OUT=${OUT:-$PWD/benchmark-results/$SERVICE-$(date +%Y%m%d-%H%M%S)}
PHP_CONTAINER=${PHP_CONTAINER:-appwrite}
RUST_CONTAINER=${RUST_CONTAINER:-appwrite-rust}
DB_CONTAINER=${DB_CONTAINER:-appwrite-postgresql}
REDIS_CONTAINER=${REDIS_CONTAINER:-appwrite-redis}

LOAD_MAX=${LOAD_MAX:-$(python3 -c 'import os; print(max(2, (os.cpu_count() or 8) // 4))')}
LOAD_WAIT=${LOAD_WAIT:-300}

load() {
    python3 -c 'import os; print(round(os.getloadavg()[0], 2))'
}

quiet() {
    local waited=0
    while python3 -c "import os, sys; sys.exit(os.getloadavg()[0] < $LOAD_MAX)"; do
        if [ "$waited" -ge "$LOAD_WAIT" ]; then
            if [ -n "${ALLOW_BUSY:-}" ]; then
                echo "compare.sh: load $(load) is above $LOAD_MAX; running anyway (ALLOW_BUSY)" >&2
                return 0
            fi
            echo "compare.sh: load $(load) stayed above $LOAD_MAX for ${LOAD_WAIT}s; stop other work or set ALLOW_BUSY=1" >&2
            exit 3
        fi
        [ "$waited" -eq 0 ] && echo "compare.sh: waiting for the load ($(load)) to fall below $LOAD_MAX" >&2
        sleep 10
        waited=$((waited + 10))
    done
}

quiet
mkdir -p "$OUT"
FAILED=
if [ -n "${RESULTS:-}" ]; then
    mkdir -p "$(dirname "$RESULTS")"
fi

cgroup_cpu_usec() {
    local id
    id=$(docker inspect -f '{{.Id}}' "$1" 2>/dev/null) || return 0
    for f in "/sys/fs/cgroup/system.slice/docker-$id.scope/cpu.stat" "/sys/fs/cgroup/docker/$id/cpu.stat"; do
        if [ -r "$f" ]; then
            awk '/^usage_usec/ {print $2}' "$f"
            return 0
        fi
    done
}

sampler() {
    local file=$1
    shift
    while true; do
        docker stats --no-stream --format '{{.Name}},{{.CPUPerc}},{{.MemUsage}}' "$@" 2>/dev/null \
            | sed "s/^/$(date +%s),/" >> "$file"
    done
}

run_step() {
    local target=$1 endpoint=$2 container=$3 mode=$4 level=$5
    local label="${target}-${mode}-${level}"
    local load_before
    load_before=$(load)
    local stats="$OUT/$label.stats.csv"
    : > "$stats"
    docker stats --no-stream --format '{{.Name}},{{.MemUsage}}' "$container" > "$OUT/$label.baseline.txt" || true
    local cpu_app_before cpu_db_before cpu_redis_before
    cpu_app_before=$(cgroup_cpu_usec "$container")
    cpu_db_before=$(cgroup_cpu_usec "$DB_CONTAINER")
    cpu_redis_before=$(cgroup_cpu_usec "$REDIS_CONTAINER")

    sampler "$stats" "$container" "$DB_CONTAINER" "$REDIS_CONTAINER" &
    local sampler_pid=$!
    local start end
    start=$(python3 -c 'import time; print(time.time())')
    if [ "$mode" = "rate" ]; then
        export APPWRITE_BENCHMARK_MODE=rate APPWRITE_BENCHMARK_RATE=$level APPWRITE_BENCHMARK_VUS=$((level / 2 + 10))
    else
        export APPWRITE_BENCHMARK_MODE=vus APPWRITE_BENCHMARK_VUS=$level
    fi
    APPWRITE_ENDPOINT=$endpoint APPWRITE_CONTROL_ENDPOINT=$CONTROL_ENDPOINT \
        APPWRITE_BENCHMARK_DURATION=$DURATION APPWRITE_BENCHMARK_HASHING=$HASHING \
        APPWRITE_BENCHMARK_SUMMARY_PATH="$OUT/$label.k6.json" \
        k6 run --quiet "$SCRIPT" > "$OUT/$label.k6.log" 2>&1 || {
        # A failed request (or any other k6 error) fails the run: keep sampling
        # and cleaning up, then report it below instead of publishing.
        echo "failed: $label (see $OUT/$label.k6.log)" >&2
        FAILED="$FAILED $label"
    }
    end=$(python3 -c 'import time; print(time.time())')
    kill "$sampler_pid" 2>/dev/null || true
    wait "$sampler_pid" 2>/dev/null || true

    local cpu_app_after cpu_db_after cpu_redis_after
    cpu_app_after=$(cgroup_cpu_usec "$container")
    cpu_db_after=$(cgroup_cpu_usec "$DB_CONTAINER")
    cpu_redis_after=$(cgroup_cpu_usec "$REDIS_CONTAINER")
    cat > "$OUT/$label.meta.json" <<EOF
{"target":"$target","mode":"$mode","level":$level,"container":"$container","start":$start,"end":$end,"load_before":$load_before,
 "cpu_app_usec":"${cpu_app_before:-}:${cpu_app_after:-}",
 "cpu_db_usec":"${cpu_db_before:-}:${cpu_db_after:-}",
 "cpu_redis_usec":"${cpu_redis_before:-}:${cpu_redis_after:-}"}
EOF
    echo "done: $label"
    sleep 5
}

step() {
    for target in $TARGETS; do
        case $target in
        php) run_step php "$PHP_ENDPOINT" "$PHP_CONTAINER" "$1" "$2" ;;
        rust) run_step rust "$RUST_ENDPOINT" "$RUST_CONTAINER" "$1" "$2" ;;
        esac
    done
}
for rate in $RATES; do
    step rate "$rate"
done
for vus in $MAX_VUS; do
    step vus "$vus"
done

if [ -n "$FAILED" ]; then
    # Report what ran, but never write a failed run to $RESULTS.
    LOAD_MAX=$LOAD_MAX SERVICE=$SERVICE DURATION=$DURATION HASHING=$HASHING PHP_ENDPOINT=$PHP_ENDPOINT RUST_ENDPOINT=$RUST_ENDPOINT DB_CONTAINER=$DB_CONTAINER \
        python3 "$HERE/report.py" "$OUT" | tee "$OUT/report.md"
    echo "compare.sh: failed steps:$FAILED; nothing written to ${RESULTS:-$OUT/$SERVICE.json}" >&2
    exit 1
fi
APPWRITE_BENCHMARK_ERROR_BUDGET=${APPWRITE_BENCHMARK_ERROR_BUDGET:-0.01} \
    LOAD_MAX=$LOAD_MAX SERVICE=$SERVICE DURATION=$DURATION HASHING=$HASHING PHP_ENDPOINT=$PHP_ENDPOINT RUST_ENDPOINT=$RUST_ENDPOINT DB_CONTAINER=$DB_CONTAINER \
    python3 "$HERE/report.py" "$OUT" --json "${RESULTS:-$OUT/$SERVICE.json}" | tee "$OUT/report.md"
