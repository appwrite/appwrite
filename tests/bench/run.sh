#!/usr/bin/env bash
# Benchmark the queue brokers across workload shapes and both concurrency axes.
#
# The question this answers is not "which broker is faster" -- at realistic handler
# cost they tie -- but "which axis do I scale". A handler that waits is absorbed by
# coroutines; a handler that computes is not, because PHP runs one coroutine at a time,
# and only processes help there. Guidance that says "scale replicas, not coroutines"
# (or the reverse) is only right for one of these shapes, so the sweep reports both.
#
# Needs Redis and NATS. Brings up the package's own compose services when Docker is
# available and they are not already reachable, and skips rather than fails when it
# cannot: the Benchmark workflow is informational, and a benchmark that reddens a PR
# because a runner has no Docker is worse than no benchmark.
set -eu

cd "$(dirname "$0")/../.." # packages/queue

REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-16379}"
NATS_URL="${NATS_URL:-nats://127.0.0.1:14225}"
MESSAGES="${MESSAGES:-600}"
REPEAT="${REPEAT:-3}"
# Consumers provision on their first receive(), and starting several at once turns that
# into a storm: measured at four processes against an already-warm single-replica stream,
# a child intermittently spent ~124s inside its first receive() while its siblings
# drained in 1.3s. Spacing the starts removes it (4/4 clean runs against 2/4). Set
# STAGGER=0 to reproduce the storm instead.
STAGGER="${STAGGER:-300}"
CORES=$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')
export REDIS_HOST REDIS_PORT NATS_URL

php -r 'exit(extension_loaded("swoole") ? 0 : 1);' || { echo "ext-swoole is required" >&2; exit 1; }
php -r 'exit(extension_loaded("redis") ? 0 : 1);' || { echo "ext-redis is required" >&2; exit 1; }

reachable() { # host port
    php -r '$s=@fsockopen($argv[1],(int)$argv[2],$e,$m,1.0); exit($s===false?1:0);' "$1" "$2"
}

NATS_HOST=$(php -r 'echo parse_url($argv[1], PHP_URL_HOST) ?: "127.0.0.1";' "$NATS_URL")
NATS_PORT=$(php -r 'echo parse_url($argv[1], PHP_URL_PORT) ?: 4222;' "$NATS_URL")

# Its own Compose project, never the default one. Tearing the default project down
# would take with it whatever the developer already had up -- redis-cluster and its
# volumes included -- which is not this script's to delete. A separate project can only
# remove what this script created, and cannot start at all if the ports are already
# taken, which is exactly the case where we should not be starting anything.
PROJECT="utopia-queue-bench-$$"
COMPOSE_STARTED=
if ! reachable "$REDIS_HOST" "$REDIS_PORT" || ! reachable "$NATS_HOST" "$NATS_PORT"; then
    if command -v docker > /dev/null 2>&1 && docker info > /dev/null 2>&1; then
        echo "starting compose services (redis, nats) in project $PROJECT" >&2
        docker compose -p "$PROJECT" up -d --wait redis nats >&2
        COMPOSE_STARTED=1
    else
        echo "queue bench skipped: needs Redis on ${REDIS_HOST}:${REDIS_PORT} and NATS on ${NATS_HOST}:${NATS_PORT}, and Docker is unavailable to start them" >&2
        exit 0
    fi
fi
# shellcheck disable=SC2064
[ -n "$COMPOSE_STARTED" ] && trap "docker compose -p '$PROJECT' down -v --remove-orphans > /dev/null 2>&1 || true" EXIT

rows=""
failed=0
bench() { # workload processes coroutines sleep_ms cpu_iters
    local name=$1 procs=$2 coros=$3 sleep=$4 iters=$5
    local out status
    # Infrastructure availability was decided above, so a non-zero exit from here is a
    # benchmark that did not produce a usable sample. Recorded, not swallowed: a green
    # `composer bench` that measured nothing is worse than a red one.
    set +e
    out=$(php tests/bench/consume.php \
        --backend=both --processes="$procs" --coroutines="$coros" \
        --sleep-ms="$sleep" --cpu-iters="$iters" --stagger="$STAGGER" \
        --messages="$MESSAGES" --repeat="$REPEAT" 2>&1)
    status=$?
    set -e

    local redis nats
    redis=$(echo "$out" | awk '$1=="redis"{print $2}')
    nats=$(echo "$out" | awk '$1=="nats"{print $2}')
    rows+="| $name | ${procs}p x ${coros}c | ${redis:-n/a} | ${nats:-n/a} |
"
    echo "  $name ${procs}p x ${coros}c -> redis ${redis:-n/a}, nats ${nats:-n/a}" >&2
    if [ "$status" -ne 0 ]; then
        failed=1
        echo "$out" >&2
    fi
}

# workload: name sleep_ms cpu_iters
#   io    - 25ms wait, models a database or HTTP round trip. Yields, so coroutines absorb it.
#   cpu   - sha256 rounds, models a render or a hash. Yields nothing, so only processes help.
#   mixed - both, which is what most real jobs are.
#
# Three cells per workload, chosen to separate the axes: a baseline, the same total
# concurrency spent on coroutines, and the same total spent on processes.
for workload in "io 25 0" "cpu 0 20000" "mixed 12 10000"; do
    read -r name sleep iters <<<"$workload"
    bench "$name" 1 1 "$sleep" "$iters"
    bench "$name" 1 4 "$sleep" "$iters"
    bench "$name" 4 1 "$sleep" "$iters"
done

table="| workload | shape | redis msg/s | nats msg/s |
|---|---|---|---|
${rows%$'\n'}"

section="### queue — workload shapes across both concurrency axes (${CORES} cores, ${MESSAGES} messages, median of ${REPEAT})

${table}

_\`Np x Mc\` = N consumer processes x M handler coroutines. \`io\` yields and should follow
coroutines; \`cpu\` does not and should follow processes; \`mixed\` is in between._"

echo
echo "$table"

# GITHUB_STEP_SUMMARY: the run's own job summary.
# BENCH_REPORT: shared file a bench script appends its section to, so the Benchmark
# workflow can collect every package into one place.
[ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '%s\n\n' "$section" >> "$GITHUB_STEP_SUMMARY"
[ -n "${BENCH_REPORT:-}" ] && printf '%s\n\n' "$section" >> "$BENCH_REPORT"

# The table is still published on failure -- the rows that did work are worth having --
# but the run reports that some cell produced nothing.
exit "$failed"
