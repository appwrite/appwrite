#!/usr/bin/env bash
# Local integration run of the Rust API against PostgreSQL and Redis.
#   PG_BIN=/path/to/pg/bin REDIS_SERVER=/path/to/redis-server BIN=/path/to/appwrite-rust tests/rust/run.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PG_BIN=${PG_BIN:?}; REDIS_SERVER=${REDIS_SERVER:?}; BIN=${BIN:?}
DATA=${DATA:-/tmp/appwrite-rust-it}
export PATH="$PG_BIN:$PATH" PGPASSWORD=password
mkdir -p "$DATA"
if [ ! -d "$DATA/pg" ]; then
  echo password > "$DATA/pw"; initdb -D "$DATA/pg" -U user --pwfile="$DATA/pw" -A md5 >/dev/null
fi
pg_ctl -D "$DATA/pg" -o "-p 5432 -k $DATA" -l "$DATA/pg.log" start >/dev/null
"$REDIS_SERVER" --port 6379 --save '' --appendonly no --daemonize yes --logfile "$DATA/redis.log"
cleanup() { kill "${SERVER_PID:-0}" 2>/dev/null || true; "$PG_BIN/pg_ctl" -D "$DATA/pg" stop -m fast >/dev/null 2>&1 || true; pkill -f "redis-server.*6379" 2>/dev/null || true; }
trap cleanup EXIT
sleep 1
psql -q -h 127.0.0.1 -U user -d postgres -c "DROP DATABASE IF EXISTS appwrite" -c "CREATE DATABASE appwrite" >/dev/null
python3 "$HERE/schema.py" _1 | psql -q -h 127.0.0.1 -U user -d appwrite -v ON_ERROR_STOP=1 >/dev/null
if [ -z "${ASSETS:-}" ]; then
  mkdir -p "$DATA/assets/app/config/locale/translations"
  echo '{"locale.country.unknown":"Unknown","countries.us":"United States"}' > "$DATA/assets/app/config/locale/translations/en.json"
fi
export _APP_OPENSSL_KEY_V1=your-secret-key
SEED=$(DB_HOST=127.0.0.1 python3 "$HERE/seed.py" test)
KEY=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["key"])' "$SEED")
export CONSOLE_SESSION=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["console"])' "$SEED")
export USER_SESSION=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["user"])' "$SEED")
export _APP_ENV=development _APP_DB_HOST=127.0.0.1 _APP_DB_USER=user _APP_DB_PASS=password _APP_DB_SCHEMA=appwrite \
  _APP_REDIS_HOST=127.0.0.1 _APP_RUST_PORT=8090 _APP_DOMAIN=localhost _APP_PWNED_PASSWORDS_DSN=mock://localhost \
  _APP_RUST_ASSETS=${ASSETS:-$DATA/assets} _APP_RUST_LOG=${LOG:-warn}
"$BIN" > "$DATA/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do "$BIN" health && break; sleep 0.1; done
ENDPOINT=http://127.0.0.1:8090/v1 PROJECT=test KEY="$KEY" python3 "$HERE/e2e.py" "$@"
if [ -n "${REDIS_CLI:-}" ]; then
  sleep 0.5
  for q in v1-functions v1-webhooks v1-deletes v1-stats-usage; do
    echo "queue $q: $("$REDIS_CLI" -p 6379 LLEN "utopia-queue.queue.$q")"
  done
  "$REDIS_CLI" -p 6379 LRANGE utopia-queue.queue.v1-functions 0 0 | head -c 600; echo
  "$REDIS_CLI" -p 6379 LRANGE utopia-queue.queue.v1-stats-usage 0 0 | head -c 900; echo
  "$REDIS_CLI" -p 6379 LRANGE utopia-queue.queue.v1-deletes 0 0 | head -c 400; echo
  "$REDIS_CLI" -p 6379 --scan --pattern 'default-cache-*' | head -5
fi
