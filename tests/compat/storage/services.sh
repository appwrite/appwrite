#!/usr/bin/env bash
# The S3-compatible service the storage cases need besides the dev stack: a
# throwaway MinIO (the image packages/storage/docker-compose.yml tests against)
# on the dev stack's network, with the bucket `compat` created at start.
#
#   tests/compat/storage/services.sh up     # start it
#   bin/compat check storage
#   tests/compat/storage/services.sh down   # stop it
#
# Endpoints match tests/compat/compat.json: compat-storage-minio:9000 for the
# PHP driver, localhost:16805 for the Rust driver and the runner. No real
# cloud service is ever called.
set -euo pipefail
cd "$(dirname "$0")"
network="${COMPAT_NETWORK:-appwrite}"
image="ghcr.io/appwrite/silo:RELEASE.2026-09-16T00-00-00Z@sha256:635197cb9f36d01bee221d34d1c7d7960f6a95c48b0b6c01d99cd13bdae51a46"

case "${1:-up}" in
up)
    docker run -d --rm --network "$network" --name compat-storage-minio -p 16805:9000 \
        --entrypoint sh "$image" -c 'mkdir -p /data/compat && exec silo server /data' >/dev/null
    for _ in $(seq 1 60); do
        if curl -fsS http://localhost:16805/minio/health/live >/dev/null 2>&1; then
            exit 0
        fi
        sleep 1
    done
    echo "MinIO did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-storage-minio >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
