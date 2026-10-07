#!/bin/sh
# The adapter suites connect to each database by service name and stop and
# start containers by name, so they run inside the compose network: in the
# `tests` service, which mounts this package (vendor/ already installed by
# `bin/monorepo test`), the root toolchain and the Docker socket.
set -e
cd "$(dirname "$0")/.."
# No arguments runs the whole e2e suite; otherwise they go to phpunit as given.
if [ $# -eq 0 ]; then
    set -- --testsuite e2e
fi
COMPOSE_FILE=docker-compose.yml docker compose exec -T tests \
    php -d memory_limit=1024M /usr/src/toolchain/phpunit/phpunit/phpunit "$@"
