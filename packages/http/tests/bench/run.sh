#!/usr/bin/env bash
# Benchmark the Swoole HTTP adapter's modes across workload shapes.
#
# Runs the bench server natively and drives it with k6, once per
# (mode x workload). Linux only: Swoole's process mode and dispatch
# tuning target Linux, and macOS caps HYPERLOOP_A's dispatch_mode=3 at
# a few req/s. On a Mac use tests/bench/docker-compose.yml instead.
#
# Worker counts follow each mode's cgroup-aware sizing, so on a 4-core
# box (e.g. a GitHub runner) this is the "4 CPU pod" scenario directly.
#
# No pipefail on purpose: the metric parsers below pipe into `head`, which
# closes the pipe early and SIGPIPEs the upstream grep. Under pipefail that
# non-zero would trip `set -e` and abort mid-sweep (dropping a row), so we
# let the parsers fall back to n/a instead.
set -eu

cd "$(dirname "$0")" # packages/http/tests/bench

command -v k6 >/dev/null || { echo "k6 is required" >&2; exit 1; }
php -r 'exit(extension_loaded("swoole") ? 0 : 1);' || { echo "ext-swoole is required" >&2; exit 1; }

PORT="${PORT:-9501}"
VUS="${VUS:-200}"
WARMUP="${WARMUP:-5s}"
DURATION="${DURATION:-20s}"
CORES=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')

rows=""
bench() { # name mode sleep_ms cpu_iters
    local name=$1 mode=$2 sleep=$3 iters=$4
    PORT=$PORT MODE=$mode SLEEP_MS=$sleep CPU_ITERS=$iters php server.php >/tmp/bench-server.log 2>&1 &
    local pid=$!
    local up=
    for _ in $(seq 1 40); do
        curl -fs -o /dev/null "http://127.0.0.1:$PORT/work" && { up=1; break; }
        sleep 0.5
    done
    if [ -z "$up" ]; then
        echo "server failed to start (mode=$mode)" >&2; cat /tmp/bench-server.log >&2
        kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true
        return 1
    fi

    local out rps p95
    out=$(k6 run --quiet \
        -e BASE_URL="http://127.0.0.1:$PORT" -e VUS="$VUS" -e WARMUP="$WARMUP" -e DURATION="$DURATION" \
        benchmark.js 2>&1 || true)
    kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true

    rps=$(echo "$out" | grep -E 'http_reqs' | grep -oE '[0-9.]+/s' | head -1)
    # value+unit until the next space, so µs/ms/s are all captured
    p95=$(echo "$out" | grep -E 'http_req_duration' | grep -oE 'p\(95\)=[^ ]+' | head -1 | cut -d= -f2)
    rows+="| $name | $mode | ${rps:-n/a} | ${p95:-n/a} |
"
    echo "  $name mode=$mode -> ${rps:-n/a} (p95 ${p95:-n/a})" >&2
}

# workload: name sleep_ms cpu_iters
#   ok  - trivial response, measures raw request throughput
#   io  - 50ms blocking sleep, models a downstream call
#   cpu - sha256 rounds, models a CPU-bound render
for workload in "ok 0 0" "io 50 0" "cpu 0 3000"; do
    read -r name sleep iters <<<"$workload"
    bench "$name" a "$sleep" "$iters"
    bench "$name" b "$sleep" "$iters"
done

table="| workload | mode | req/s | p95 |
|---|---|---|---|
${rows%$'\n'}"

section="### http — Swoole modes (${CORES} cores, ${VUS} VUs, ${DURATION}/run)

${table}

_a = HYPERLOOP_A (process), b = HYPERLOOP_B (coroutine)_"

echo
echo "$table"

# GITHUB_STEP_SUMMARY: the run's own job summary.
# BENCH_REPORT: shared file a bench script appends its section to, so a
# caller (the Benchmark workflow) can collect every package into one place.
[ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n\n' "$section" >> "$GITHUB_STEP_SUMMARY"
[ -n "${BENCH_REPORT:-}" ] && printf '%s\n\n' "$section" >> "$BENCH_REPORT"

exit 0
