#!/usr/bin/env bash
# Start MinIO from the root compose stack and run utopia-storage MinIO E2E tests.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PORT="${S3_PORT:-9805}"
BUCKET="${S3_BUCKET:-utopia-storage-test}"
ACCESS_KEY="${S3_ACCESS_KEY:-minioadmin}"
SECRET_KEY="${S3_SECRET:-minioadmin}"
REGION="${S3_REGION:-us-east-1}"

COMPOSE=(docker compose -f "$ROOT/docker-compose.test.yml")
if ! docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker-compose -f "$ROOT/docker-compose.test.yml")
fi

log() { printf '==> %s\n' "$*"; }

wait_ready() {
  local url="http://127.0.0.1:${PORT}/minio/health/live"
  for _ in $(seq 1 60); do
    if curl -sf "$url" >/dev/null; then
      return 0
    fi
    sleep 0.5
  done
  echo "MinIO did not become ready at $url" >&2
  echo "Start it with: docker compose -f docker-compose.test.yml up -d --wait minio" >&2
  return 1
}

if [[ "${SKIP_MINIO_START:-}" != "1" ]]; then
  log "Starting MinIO via docker-compose.test.yml"
  "${COMPOSE[@]}" up -d --wait minio
  wait_ready
fi

export S3_BUCKET="$BUCKET"
export S3_ACCESS_KEY="$ACCESS_KEY"
export S3_SECRET="$SECRET_KEY"
export S3_REGION="$REGION"
export S3_PORT="$PORT"
# Path-style on IPv4 is the reliable default (*.localhost often resolves to ::1).
export S3_PATH_HOST="${S3_PATH_HOST:-http://127.0.0.1:${PORT}}"
export S3_HOST="${S3_HOST:-http://127.0.0.1:${PORT}/${BUCKET}}"
export S3_VIRTUAL_HOST="${S3_VIRTUAL_HOST:-http://${BUCKET}.localhost:${PORT}}"

log "Running MinIO E2E tests"
cargo test -p utopia-storage --features s3 --test e2e_minio -- --nocapture "$@"
