#!/bin/sh
# The e2e suites connect to `mongo` by service name, so they run inside the
# compose network: in the `tests` service, which mounts this package (vendor/
# already installed by `bin/monorepo test`) and the root toolchain.
set -e
cd "$(dirname "$0")/.."
docker compose exec -T tests \
    php -d memory_limit=1024M /usr/src/toolchain/phpunit/phpunit/phpunit --testsuite e2e "$@"
