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
# The MariaDB and MySQL adapters' cases run on the images of the library's
# own CI and Appwrite's docker-compose.yml (mariadb:10.11, mysql:8.0.43):
# `database-mariadb` (compat-database-mariadb:3306, localhost:16306) and
# `database-mysql` (compat-database-mysql:3306, localhost:16307), where a
# case's schema is a database (`${ns}`).
#
# The extensions and the `utf8_ci_ai` collation Postgres::create() installs
# are server- and database-wide; they are created here once, so the two
# runtimes' concurrent CREATE ... IF NOT EXISTS never race each other.
#
# A Redis next to it (compat-database-redis, the dev stack's image) holds
# the Redis caches and the Redis database adapter's keys (`database-redis*`
# in compat.json): database 0 is PHP's private cache, 1 Rust's, 4 and 5
# their second ones (a Mirror's destination), 2 a cache both runtimes share
# (interop) and 3 the Redis adapter's keyspace, whose keys carry the case
# namespace. It is the cases' own server, so `db.open`
# may flush a private cache database.
set -euo pipefail
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_POSTGRES_IMAGE:-appwrite/postgres:0.1.0}"
mariadb_image="${COMPAT_MARIADB_IMAGE:-mariadb:10.11}"
mysql_image="${COMPAT_MYSQL_IMAGE:-mysql:8.0.43}"

# Starts a MariaDB/MySQL server (container, image, host port, server and
# client binaries) and waits until it answers queries.
mysql_up() {
    local name="$1" img="$2" port="$3" server="$4" client="$5"
    if ! docker ps --format '{{.Names}}' | grep -qx "$name"; then
        docker run -d --rm --network "$network" --name "$name" \
            -e MYSQL_ROOT_PASSWORD=password -p "$port:3306" "$img" \
            "$server" --max_allowed_packet=1G --max_connections=500 >/dev/null
    fi
    for _ in $(seq 1 120); do
        if docker exec "$name" "$client" --protocol=tcp -h127.0.0.1 -uroot -ppassword -e 'SELECT 1' >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "$name did not become ready" >&2
    return 1
}

case "${1:-up}" in
up)
    if ! docker ps --format '{{.Names}}' | grep -qx compat-database-postgres; then
        docker run -d --rm --network "$network" --name compat-database-postgres \
            -e POSTGRES_USER=root -e POSTGRES_PASSWORD=password -e POSTGRES_DB=root \
            -p 16432:5432 "$image" postgres -c max_connections=500 >/dev/null
    fi
    if ! docker ps --format '{{.Names}}' | grep -qx compat-database-redis; then
        docker run -d --rm --network "$network" --name compat-database-redis \
            -p 16479:6379 "${COMPAT_REDIS_IMAGE:-redis:7.4.7-alpine}" >/dev/null
    fi
    for _ in $(seq 1 90); do
        if docker exec compat-database-redis redis-cli ping >/dev/null 2>&1 \
            && docker exec compat-database-postgres pg_isready -U root -d root >/dev/null 2>&1 \
            && docker exec compat-database-postgres psql -U root -d root -Atc 'select 1' >/dev/null 2>&1; then
            docker exec compat-database-postgres psql -U root -d root -q -v ON_ERROR_STOP=1 \
                -c 'CREATE EXTENSION IF NOT EXISTS postgis' \
                -c 'CREATE EXTENSION IF NOT EXISTS vector' \
                -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm' \
                -c "CREATE COLLATION IF NOT EXISTS utf8_ci_ai (provider = icu, locale = 'und-u-ks-level1', deterministic = false)" \
                >/dev/null
            # A second database for a Mirror's destination
            # (`database-postgres-mirror`), set up the same way.
            if [ "$(docker exec compat-database-postgres psql -U root -d root -Atc "SELECT 1 FROM pg_database WHERE datname = 'mirror'")" != 1 ]; then
                docker exec compat-database-postgres psql -U root -d root -q -c 'CREATE DATABASE mirror' >/dev/null
            fi
            docker exec compat-database-postgres psql -U root -d mirror -q -v ON_ERROR_STOP=1 \
                -c 'CREATE EXTENSION IF NOT EXISTS postgis' \
                -c 'CREATE EXTENSION IF NOT EXISTS vector' \
                -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm' \
                -c "CREATE COLLATION IF NOT EXISTS utf8_ci_ai (provider = icu, locale = 'und-u-ks-level1', deterministic = false)" \
                >/dev/null
            mysql_up compat-database-mariadb "$mariadb_image" 16306 mariadbd mariadb
            mysql_up compat-database-mysql "$mysql_image" 16307 mysqld mysql
            exit 0
        fi
        sleep 1
    done
    echo "compat-database-postgres or compat-database-redis did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-database-postgres compat-database-redis >/dev/null 2>&1 || true
    docker stop compat-database-mariadb >/dev/null 2>&1 || true
    docker stop compat-database-mysql >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
