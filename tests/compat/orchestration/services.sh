#!/usr/bin/env bash
# What the orchestration cases need besides the dev stack, as throwaway
# containers:
#
#   tests/compat/orchestration/services.sh up     # start them
#   bin/compat check orchestration
#   tests/compat/orchestration/services.sh down   # stop them, and remove what the cases left
#
# - compat-orchestration-registry: a local image registry on localhost:16905
#   holding localhost:16905/compat/alpine:latest, so pulls and runs never
#   reach Docker Hub.
# - compat-orchestration-php: the PHP side's Docker host (fixtures/server.php,
#   on compat-orchestration-php:8080 in the network and localhost:16906 from
#   the host), with the Docker socket at /var/run/docker.sock where
#   utopia-php/orchestration expects it; the PHP driver's container has
#   none. The Rust side's DockerCli runs this container's docker client too
#   (the `docker` script next to this one), so both send the same client's
#   commands.
#
# Every container and network the cases create is named orchestration-cx...
# and lives on the local daemon only.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
source_image="alpine:latest"
registry="localhost:16905"

cleanup() {
    local ids networks
    ids=$(docker ps -aq --filter name=orchestration-cx)
    if [ -n "$ids" ]; then
        # shellcheck disable=SC2086
        docker rm -f $ids >/dev/null 2>&1 || true
    fi
    networks=$(docker network ls -q --filter name=orchestration-cx)
    if [ -n "$networks" ]; then
        # shellcheck disable=SC2086
        docker network rm $networks >/dev/null 2>&1 || true
    fi
}

case "${1:-up}" in
up)
    mkdir -p /tmp/compat-fs
    docker run -d --rm --name compat-orchestration-registry -p 16905:5000 registry:2 >/dev/null
    docker run -d --rm --network "$network" --name compat-orchestration-php -p 16906:8080 \
        -v /var/run/docker.sock:/var/run/docker.sock \
        -v "$PWD/tests:/usr/src/code/tests" -v "$PWD/packages:/usr/src/code/packages" \
        -v /tmp/compat-fs:/tmp/compat-fs -w /usr/src/code --entrypoint php "$image" \
        -d max_execution_time=0 -d memory_limit=1G -d display_errors=stderr \
        -S 0.0.0.0:8080 /usr/src/code/tests/compat/orchestration/fixtures/server.php >/dev/null
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null "http://$registry/v2/" && curl -sf -o /dev/null http://localhost:16906/ping; then
            docker image inspect "$source_image" >/dev/null 2>&1 || docker pull -q "$source_image" >/dev/null
            docker tag "$source_image" "$registry/compat/alpine:latest"
            docker push -q "$registry/compat/alpine:latest" >/dev/null
            exit 0
        fi
        sleep 1
    done
    echo "orchestration services did not become ready" >&2
    exit 1
    ;;
down)
    cleanup
    docker stop compat-orchestration-php compat-orchestration-registry >/dev/null 2>&1 || true
    ;;
clean)
    cleanup
    ;;
*)
    echo "usage: $0 up|down|clean" >&2
    exit 2
    ;;
esac
