#!/usr/bin/env bash
# Benchmark Users create/get against PHP and Rust backends.
# Usage (from Appwrite repo root, with stack up):
#   ./3.x.x/benchmarks/users/run.sh
#
# Env:
#   APPWRITE_ENDPOINT   default http://127.0.0.1
#   PROJECT_ID          required (or set via seed)
#   API_KEY             required
#   N                   iterations (default 200)
#   RUST_DIRECT         optional direct Rust URL (bypass Traefik), e.g. http://127.0.0.1:8080
#   PHP_DIRECT          optional direct PHP URL

set -euo pipefail

ENDPOINT="${APPWRITE_ENDPOINT:-http://127.0.0.1}"
N="${N:-200}"
PROJECT_ID="${PROJECT_ID:?PROJECT_ID required}"
API_KEY="${API_KEY:?API_KEY required}"

HDR=(-H "X-Appwrite-Project: ${PROJECT_ID}" -H "X-Appwrite-Key: ${API_KEY}" -H "Content-Type: application/json")

bench() {
  local name="$1"
  local base="$2"
  local start end elapsed ops
  start=$(date +%s.%N)
  for i in $(seq 1 "$N"); do
    local id="bench_${i}_$$"
    curl -fsS -X POST "${base}/v1/users" "${HDR[@]}" \
      -d "{\"userId\":\"${id}\",\"email\":\"${id}@bench.local\",\"password\":\"password123\",\"name\":\"Bench\"}" \
      >/dev/null
    curl -fsS "${base}/v1/users/${id}" "${HDR[@]}" >/dev/null
  done
  end=$(date +%s.%N)
  elapsed=$(awk -v s="$start" -v e="$end" 'BEGIN{printf "%.6f", e-s}')
  ops=$(awk -v n="$N" -v e="$elapsed" 'BEGIN{printf "%.2f", (n*2)/e}')
  echo "${name}: iterations=${N} create+get pairs, elapsed_s=${elapsed}, ops_per_s=${ops}"
}

echo "=== Users create+get benchmark (N=${N}) ==="
if [[ -n "${PHP_DIRECT:-}" ]]; then
  bench "php_direct" "$PHP_DIRECT"
else
  # Force PHP by calling the appwrite container network name if available; else Traefik
  # with a header that rust doesn't get - for fair PHP measure, use PHP_DIRECT.
  echo "Set PHP_DIRECT=http://appwrite (from inside compose) or host-mapped PHP port for PHP column"
fi

if [[ -n "${RUST_DIRECT:-}" ]]; then
  bench "rust_direct" "$RUST_DIRECT"
else
  bench "traefik_users" "$ENDPOINT"
fi
