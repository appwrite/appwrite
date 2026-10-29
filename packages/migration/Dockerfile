FROM supabase/postgres:15.1.0.96 AS supabase-db

COPY tests/fixtures/supabase/1_globals.sql /docker-entrypoint-initdb.d/1_globals.sql
COPY tests/fixtures/supabase/2_main.sql /docker-entrypoint-initdb.d/2_main.sql

RUN rm -rf /docker-entrypoint-initdb.d/migrate.sh

FROM postgres:alpine3.18 AS nhost-db

COPY tests/fixtures/nhost/1_globals.sql /docker-entrypoint-initdb.d/1_globals.sql
COPY tests/fixtures/nhost/2_main.sql /docker-entrypoint-initdb.d/2_main.sql
