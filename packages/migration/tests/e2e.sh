#!/bin/sh
# The source suites reach Supabase and NHost by service name, so they run
# inside the compose network: in the `tests` service, which mounts this package
# (vendor/ already installed by `bin/monorepo test`) and the root toolchain.
set -e
cd "$(dirname "$0")/.."
if [ $# -eq 0 ]; then
    set -- --testsuite e2e
fi
COMPOSE_FILE=docker-compose.yml docker compose exec -T tests \
    php /usr/src/toolchain/phpunit/phpunit/phpunit "$@"
