/**
 * SQL builders for Postgres database usage and performance metrics.
 * All queries are read-only and safe to run via the compute SQL API.
 */

import { prefixPostgresSqlComment } from '@/lib/postgres-sql'

export const POSTGRES_METRICS_SNAPSHOT_SQL = prefixPostgresSqlComment(
  `
SELECT
  d.numbackends::bigint AS active_connections,
  d.xact_commit::bigint AS xact_commit,
  d.xact_rollback::bigint AS xact_rollback,
  d.blks_read::bigint AS blks_read,
  d.blks_hit::bigint AS blks_hit,
  d.tup_returned::bigint AS tup_returned,
  d.tup_fetched::bigint AS tup_fetched,
  d.tup_inserted::bigint AS tup_inserted,
  d.tup_updated::bigint AS tup_updated,
  d.tup_deleted::bigint AS tup_deleted,
  d.conflicts::bigint AS conflicts,
  d.deadlocks::bigint AS deadlocks,
  d.temp_bytes::bigint AS temp_bytes,
  pg_database_size(current_database())::bigint AS database_size_bytes,
  (
    SELECT count(*)::bigint
    FROM pg_stat_activity
    WHERE datname = current_database()
      AND pid != pg_backend_pid()
  ) AS total_connections,
  (
    SELECT count(*)::bigint
    FROM pg_stat_activity
    WHERE datname = current_database()
      AND state = 'active'
      AND pid != pg_backend_pid()
  ) AS active_queries,
  (
    SELECT count(*)::bigint
    FROM pg_stat_activity
    WHERE datname = current_database()
      AND state = 'idle in transaction'
      AND pid != pg_backend_pid()
  ) AS idle_in_transaction,
  (
    SELECT count(*)::bigint
    FROM pg_stat_activity
    WHERE datname = current_database()
      AND state = 'active'
      AND query_start < now() - interval '10 seconds'
      AND pid != pg_backend_pid()
  ) AS long_running_queries,
  pg_postmaster_start_time() AS server_started_at,
  EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))::bigint AS uptime_seconds
FROM pg_stat_database d
WHERE d.datname = current_database()
`.trim(),
  'Load metrics snapshot',
)

export const POSTGRES_METRICS_CONNECTION_STATES_SQL = prefixPostgresSqlComment(
  `
SELECT
  COALESCE(state, 'unknown') AS state,
  count(*)::bigint AS count
FROM pg_stat_activity
WHERE datname = current_database()
  AND pid != pg_backend_pid()
GROUP BY state
ORDER BY count DESC
`.trim(),
  'List connection states',
)

export const POSTGRES_METRICS_CONNECTION_APPS_SQL = prefixPostgresSqlComment(
  `
SELECT
  COALESCE(NULLIF(application_name, ''), 'unknown') AS application_name,
  count(*)::bigint AS count
FROM pg_stat_activity
WHERE datname = current_database()
  AND pid != pg_backend_pid()
GROUP BY application_name
ORDER BY count DESC
LIMIT 12
`.trim(),
  'List connection apps',
)

export const POSTGRES_ACTIVE_CONNECTIONS_SQL = prefixPostgresSqlComment(
  `
SELECT
  pid,
  backend_type,
  (backend_type = 'client backend') AS is_client_backend,
  usename AS username,
  datname AS database,
  NULLIF(application_name, '') AS application_name,
  COALESCE(host(client_addr), '') AS client_host,
  client_port,
  state,
  wait_event_type,
  wait_event,
  backend_start,
  query_start,
  state_change,
  query
FROM pg_stat_activity
WHERE pid != pg_backend_pid()
ORDER BY
  CASE backend_type
    WHEN 'client backend' THEN 0
    ELSE 1
  END,
  CASE state
    WHEN 'active' THEN 0
    WHEN 'idle in transaction' THEN 1
    WHEN 'idle in transaction (aborted)' THEN 2
    ELSE 3
  END,
  query_start NULLS LAST,
  pid ASC
`.trim(),
  'List active connections',
)

export const POSTGRES_METRICS_TABLE_ACTIVITY_SQL = prefixPostgresSqlComment(
  `
SELECT
  schemaname,
  relname AS table_name,
  pg_total_relation_size(relid)::bigint AS total_bytes,
  n_live_tup::bigint AS live_tuples,
  n_dead_tup::bigint AS dead_tuples,
  seq_scan::bigint AS seq_scans,
  idx_scan::bigint AS idx_scans,
  (n_tup_ins + n_tup_upd + n_tup_del)::bigint AS write_operations,
  (seq_scan + idx_scan)::bigint AS read_operations
FROM pg_stat_user_tables
WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
ORDER BY total_bytes DESC
LIMIT 12
`.trim(),
  'List table activity',
)
