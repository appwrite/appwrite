#!/usr/bin/env bash
# Loads the NHost or Supabase dump of the PHP suite (mounted at /dump) into a
# plain PostgreSQL: statements a stock server lacks the extensions for
# (Supabase's pgsodium, pg_graphql, ...) fail and are skipped; the tables the
# sources read (auth.users, storage.*, public.*) load.
set -u
for file in /dump/1_globals.sql /dump/2_main.sql; do
    psql -v ON_ERROR_STOP=0 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -q -f "$file" >/dev/null 2>&1 || true
done
