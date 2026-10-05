#!/usr/bin/env bash
# Benchmark the DNS server across its transports: start the sample Swoole
# server from the test resources on offset ports and drive UDP, TCP, and
# DNS over HTTPS with dnspyre. Requires ext-swoole and jq; fetches the
# dnspyre release binary when it is not on the PATH.
set -eu

cd "$(dirname "$0")/../.." # packages/dns

php -r 'exit(extension_loaded("swoole") ? 0 : 1);' || { echo "ext-swoole is required" >&2; exit 1; }
command -v jq >/dev/null || { echo "jq is required" >&2; exit 1; }

PORT="${PORT:-8053}"
QUERIES="${QUERIES:-250}" # dnspyre -n: repetitions per worker per domain
CONCURRENCY="${CONCURRENCY:-20}"
DOMAINS="${DOMAINS:-dev.appwrite.io dev2.appwrite.io alias.appwrite.io}"

DNSPYRE_VERSION="3.11.1"
if [ "$(dnspyre --version 2>/dev/null || true)" != "$DNSPYRE_VERSION" ]; then
    os=$(uname -s | tr '[:upper:]' '[:lower:]')
    arch=$(uname -m)
    case "$arch" in x86_64) arch=amd64 ;; aarch64) arch=arm64 ;; esac
    dir="${TMPDIR:-/tmp}/dnspyre-${DNSPYRE_VERSION}"
    if [ ! -x "$dir/dnspyre" ]; then
        mkdir -p "$dir"
        curl -fsSL "https://github.com/Tantalor93/dnspyre/releases/download/v${DNSPYRE_VERSION}/dnspyre_${os}_${arch}.tar.gz" | tar xz -C "$dir"
    fi
    PATH="$dir:$PATH"
fi

PORT=$PORT HTTP_PORT=$((PORT + 1)) PROXY_PORT=$((PORT + 2)) \
    php tests/resources/server.php >/tmp/dns-bench-server.log 2>&1 &
pid=$!
trap 'kill $pid 2>/dev/null || true' EXIT

up=
for _ in $(seq 1 40); do
    if php tests/bench/probe.php "$PORT" 2>/dev/null; then up=1; break; fi
    sleep 0.5
done
if [ -z "$up" ]; then
    echo "server failed to start" >&2
    cat /tmp/dns-bench-server.log >&2
    exit 1
fi

rows=""
bench() { # label [dnspyre args...]
    local label=$1
    shift
    local json row
    # shellcheck disable=SC2086 # DOMAINS is a deliberate word-split list
    json=$(dnspyre -n "$QUERIES" -c "$CONCURRENCY" --json --no-color "$@" $DOMAINS 2>/dev/null)
    row=$(echo "$json" | jq -r --arg label "$label" \
        '"| \($label) | \(.queriesPerSecond) | \(.totalSuccessResponses)/\(.totalRequests) | \(.latencyStats.p50Ms) | \(.latencyStats.p95Ms) | \(.latencyStats.p99Ms) | \(.latencyStats.maxMs) |"')
    rows+="$row"$'\n'
    echo "  $label -> $(echo "$json" | jq -r '"\(.queriesPerSecond) req/s, \(.totalSuccessResponses)/\(.totalRequests) ok"')" >&2
}

bench udp --server "127.0.0.1:$PORT"
bench tcp --tcp --server "127.0.0.1:$PORT"
bench doh --server "http://127.0.0.1:$((PORT + 1))/dns-query"

CORES=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')
table="| transport | req/s | ok | p50 ms | p95 | p99 | max |
|---|---|---|---|---|---|---|
${rows%$'\n'}"

section="### dns — transport throughput (${CORES} cores, ${QUERIES} repeats x ${CONCURRENCY} workers x 3 domains per transport)

${table}"

echo
echo "$table"

# GITHUB_STEP_SUMMARY: the run's own job summary.
# BENCH_REPORT: shared file a bench script appends its section to, so a
# caller (the Benchmark workflow) can collect every package into one place.
[ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n\n' "$section" >> "$GITHUB_STEP_SUMMARY"
[ -n "${BENCH_REPORT:-}" ] && printf '%s\n\n' "$section" >> "$BENCH_REPORT"

exit 0
