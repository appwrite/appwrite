#!/usr/bin/env bash
# Compares the PHP and Rust Users APIs under identical load and reports
# throughput, latency percentiles, CPU time per request and memory.
#
# Prerequisites: the dev stack running with the Rust API enabled
#   COMPOSE_PROFILES=postgresql,rust docker compose up -d --wait
# and k6 on PATH. Once the instance has an organization, export
# APPWRITE_ADMIN_EMAIL for an owner of it (see users.js).
#
#   tests/benchmarks/users-compare.sh                 # default ladder
#   RATES="100 400 800" DURATION=120s tests/benchmarks/users-compare.sh
#
# Each step runs the same k6 workload (tests/benchmarks/users.js) against
# PHP (port 9501, direct) and Rust (port 9530, direct) while sampling the
# containers. CPU is read from cgroup counters when available (Linux) and
# otherwise integrated from `docker stats`.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PHP_ENDPOINT=${PHP_ENDPOINT:-http://localhost:9501/v1}
RUST_ENDPOINT=${RUST_ENDPOINT:-http://localhost:9530/v1}
CONTROL_ENDPOINT=${CONTROL_ENDPOINT:-$PHP_ENDPOINT}
RATES=${RATES:-"50 200 500"}
MAX_VUS=${MAX_VUS:-"50 200"}
DURATION=${DURATION:-60s}
HASHING=${HASHING:-false}
OUT=${OUT:-$PWD/benchmark-results/$(date +%Y%m%d-%H%M%S)}
PHP_CONTAINER=${PHP_CONTAINER:-appwrite}
RUST_CONTAINER=${RUST_CONTAINER:-appwrite-rust}
DB_CONTAINER=${DB_CONTAINER:-appwrite-postgresql}
REDIS_CONTAINER=${REDIS_CONTAINER:-appwrite-redis}

mkdir -p "$OUT"

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
        k6 run --quiet "$HERE/users.js" > "$OUT/$label.k6.log" 2>&1 || true
    end=$(python3 -c 'import time; print(time.time())')
    kill "$sampler_pid" 2>/dev/null || true
    wait "$sampler_pid" 2>/dev/null || true

    local cpu_app_after cpu_db_after cpu_redis_after
    cpu_app_after=$(cgroup_cpu_usec "$container")
    cpu_db_after=$(cgroup_cpu_usec "$DB_CONTAINER")
    cpu_redis_after=$(cgroup_cpu_usec "$REDIS_CONTAINER")
    cat > "$OUT/$label.meta.json" <<EOF
{"target":"$target","mode":"$mode","level":$level,"container":"$container","start":$start,"end":$end,
 "cpu_app_usec":"${cpu_app_before:-}:${cpu_app_after:-}",
 "cpu_db_usec":"${cpu_db_before:-}:${cpu_db_after:-}",
 "cpu_redis_usec":"${cpu_redis_before:-}:${cpu_redis_after:-}"}
EOF
    echo "done: $label"
    sleep 5
}

for rate in $RATES; do
    run_step php "$PHP_ENDPOINT" "$PHP_CONTAINER" rate "$rate"
    run_step rust "$RUST_ENDPOINT" "$RUST_CONTAINER" rate "$rate"
done
for vus in $MAX_VUS; do
    run_step php "$PHP_ENDPOINT" "$PHP_CONTAINER" vus "$vus"
    run_step rust "$RUST_ENDPOINT" "$RUST_CONTAINER" vus "$vus"
done

python3 "$HERE/users-report.py" "$OUT" | tee "$OUT/report.md"
