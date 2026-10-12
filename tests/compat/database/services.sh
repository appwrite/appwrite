#!/usr/bin/env bash
# The PostgreSQL the database cases run against, as a throwaway container on
# the dev stack's network: the image of docker-compose.yml's `postgresql`
# service (appwrite/postgres, with PostGIS, pgvector and pg_trgm), so both
# runtimes see the server Appwrite runs on.
#
#   tests/compat/database/services.sh up     # start it
#   bin/compat check database
#   tests/compat/database/services.sh down   # stop it
#
# Endpoints match tests/compat/compat.json (`database-postgres`): the PHP
# driver reaches compat-database-postgres:5432 on the network, the Rust
# driver and the runner localhost:16432. Each case runs in its own schema
# (`${ns}`), so the two runtimes never share tables.
#
# The extensions and the `utf8_ci_ai` collation Postgres::create() installs
# are server- and database-wide; they are created here once, so the two
# runtimes' concurrent CREATE ... IF NOT EXISTS never race each other.
set -euo pipefail
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_POSTGRES_IMAGE:-appwrite/postgres:0.1.0}"

case "${1:-up}" in
up)
    # MongoDB (`database-mongo`, engine "mongo"): a replica set as
    # tests/compat/mongo/services.sh starts one, on port 16018.
    COMPAT_MONGO_NAME=compat-database-mongo COMPAT_MONGO_PORT=16018 "$(dirname "$0")/../mongo/services.sh" up
    if ! docker ps --format '{{.Names}}' | grep -qx compat-database-postgres; then
        docker run -d --rm --network "$network" --name compat-database-postgres \
            -e POSTGRES_USER=root -e POSTGRES_PASSWORD=password -e POSTGRES_DB=root \
            -p 16432:5432 "$image" postgres -c max_connections=500 >/dev/null
    fi
    for _ in $(seq 1 90); do
        if docker exec compat-database-postgres pg_isready -U root -d root >/dev/null 2>&1 \
            && docker exec compat-database-postgres psql -U root -d root -Atc 'select 1' >/dev/null 2>&1; then
            docker exec compat-database-postgres psql -U root -d root -q -v ON_ERROR_STOP=1 \
                -c 'CREATE EXTENSION IF NOT EXISTS postgis' \
                -c 'CREATE EXTENSION IF NOT EXISTS vector' \
                -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm' \
                -c "CREATE COLLATION IF NOT EXISTS utf8_ci_ai (provider = icu, locale = 'und-u-ks-level1', deterministic = false)" \
                >/dev/null
            exit 0
        fi
        sleep 1
    done
    echo "compat-database-postgres did not become ready" >&2
    exit 1
    ;;
down)
    COMPAT_MONGO_NAME=compat-database-mongo "$(dirname "$0")/../mongo/services.sh" down
    docker stop compat-database-postgres >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
