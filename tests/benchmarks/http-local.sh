#!/usr/bin/env bash
set -euo pipefail

export K6_WEB_DASHBOARD="${K6_WEB_DASHBOARD:-true}"
export K6_WEB_DASHBOARD_HOST="${K6_WEB_DASHBOARD_HOST:-127.0.0.1}"
export K6_WEB_DASHBOARD_PORT="${K6_WEB_DASHBOARD_PORT:-5665}"
export K6_WEB_DASHBOARD_EXPORT="${K6_WEB_DASHBOARD_EXPORT:-/tmp/appwrite-k6-report.html}"
export APPWRITE_ENDPOINT="${APPWRITE_ENDPOINT:-http://localhost/v1}"
export APPWRITE_WORKER_TIMEOUT_MS="${APPWRITE_WORKER_TIMEOUT_MS:-120000}"
export APPWRITE_BENCHMARK_SUMMARY_PATH="${APPWRITE_BENCHMARK_SUMMARY_PATH:-/tmp/appwrite-k6-summary.json}"

samples_path="${APPWRITE_BENCHMARK_SAMPLES_PATH:-/tmp/appwrite-k6-samples.json}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"

exec k6 run --out "json=${samples_path}" "$@" "${repo_root}/tests/benchmarks/http.js"
