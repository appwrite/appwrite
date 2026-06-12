/**
 * SQL builders for Postgres database usage and performance metrics.
 * All queries are read-only and safe to run via the compute SQL API.
 */

export const POSTGRES_METRICS_SNAPSHOT_SQL = `
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
  ) AS active_queries
FROM pg_stat_database d
WHERE d.datname = current_database()
`.trim()

export const POSTGRES_METRICS_CONNECTION_STATES_SQL = `
SELECT
  COALESCE(state, 'unknown') AS state,
  count(*)::bigint AS count
FROM pg_stat_activity
WHERE datname = current_database()
  AND pid != pg_backend_pid()
GROUP BY state
ORDER BY count DESC
`.trim()

export const POSTGRES_METRICS_TABLE_ACTIVITY_SQL = `
SELECT
  schemaname,
  relname AS table_name,
  pg_total_relation_size(relid)::bigint AS total_bytes,
  n_live_tup::bigint AS live_tuples,
  n_dead_tup::bigint AS dead_tuples,
  (n_tup_ins + n_tup_upd + n_tup_del)::bigint AS write_operations,
  (seq_scan + idx_scan)::bigint AS read_operations
FROM pg_stat_user_tables
WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
ORDER BY total_bytes DESC
LIMIT 12
`.trim()
