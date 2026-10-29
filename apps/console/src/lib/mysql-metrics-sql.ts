/**
 * SQL builders for MySQL database usage and performance metrics.
 * All queries are read-only and safe to run via the compute SQL API.
 */

import { prefixMysqlSqlComment } from '@/lib/mysql-sql'

function statusValue(name: string): string {
  return `(
    SELECT CAST(VARIABLE_VALUE AS UNSIGNED)
    FROM performance_schema.global_status
    WHERE VARIABLE_NAME = '${name}'
    LIMIT 1
  )`
}

export const MYSQL_METRICS_SNAPSHOT_SQL = prefixMysqlSqlComment(
  `
SELECT
  ${statusValue('Threads_connected')} AS active_connections,
  ${statusValue('Com_commit')} AS xact_commit,
  ${statusValue('Com_rollback')} AS xact_rollback,
  ${statusValue('Innodb_buffer_pool_reads')} AS blks_read,
  ${statusValue('Innodb_buffer_pool_read_requests')} AS blks_hit,
  ${statusValue('Innodb_rows_read')} AS tup_returned,
  ${statusValue('Innodb_rows_read')} AS tup_fetched,
  ${statusValue('Innodb_rows_inserted')} AS tup_inserted,
  ${statusValue('Innodb_rows_updated')} AS tup_updated,
  ${statusValue('Innodb_rows_deleted')} AS tup_deleted,
  0 AS conflicts,
  ${statusValue('Innodb_deadlocks')} AS deadlocks,
  ${statusValue('Created_tmp_disk_tables')} AS temp_bytes,
  (
    SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
  ) AS database_size_bytes,
  (
    SELECT COUNT(*)
    FROM information_schema.PROCESSLIST
  ) AS total_connections,
  (
    SELECT COUNT(*)
    FROM information_schema.PROCESSLIST
    WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID')
  ) AS active_queries,
  0 AS idle_in_transaction,
  (
    SELECT COUNT(*)
    FROM information_schema.PROCESSLIST
    WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID')
      AND TIME >= 10
  ) AS long_running_queries,
  NULL AS server_started_at,
  ${statusValue('Uptime')} AS uptime_seconds
`.trim(),
  'Load metrics snapshot',
)

export const MYSQL_METRICS_CONNECTION_STATES_SQL = prefixMysqlSqlComment(
  `
SELECT
  COALESCE(NULLIF(COMMAND, ''), 'unknown') AS state,
  COUNT(*) AS count
FROM information_schema.PROCESSLIST
GROUP BY COMMAND
ORDER BY count DESC
`.trim(),
  'List connection states',
)

export const MYSQL_METRICS_CONNECTION_APPS_SQL = prefixMysqlSqlComment(
  `
SELECT
  COALESCE(NULLIF(USER, ''), 'unknown') AS application_name,
  COUNT(*) AS count
FROM information_schema.PROCESSLIST
GROUP BY USER
ORDER BY count DESC
LIMIT 12
`.trim(),
  'List connection apps',
)

export const MYSQL_ACTIVE_CONNECTIONS_SQL = prefixMysqlSqlComment(
  `
SELECT
  ID AS pid,
  'client backend' AS backend_type,
  TRUE AS is_client_backend,
  USER AS username,
  DB AS \`database\`,
  NULL AS application_name,
  SUBSTRING_INDEX(HOST, ':', 1) AS client_host,
  CASE
    WHEN HOST LIKE '%:%' THEN CAST(SUBSTRING_INDEX(HOST, ':', -1) AS UNSIGNED)
    ELSE NULL
  END AS client_port,
  COMMAND AS state,
  NULL AS wait_event_type,
  STATE AS wait_event,
  NULL AS backend_start,
  NULL AS query_start,
  NULL AS state_change,
  INFO AS query
FROM information_schema.PROCESSLIST
ORDER BY
  CASE COMMAND
    WHEN 'Query' THEN 0
    WHEN 'Execute' THEN 0
    WHEN 'Sleep' THEN 2
    ELSE 1
  END,
  TIME DESC,
  ID ASC
`.trim(),
  'List active connections',
)

export const MYSQL_METRICS_TABLE_ACTIVITY_SQL = prefixMysqlSqlComment(
  `
SELECT
  TABLE_SCHEMA AS schemaname,
  TABLE_NAME AS table_name,
  (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) AS total_bytes,
  COALESCE(TABLE_ROWS, 0) AS live_tuples,
  0 AS dead_tuples,
  0 AS seq_scans,
  0 AS idx_scans,
  0 AS write_operations,
  0 AS read_operations
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
ORDER BY total_bytes DESC
LIMIT 12
`.trim(),
  'List table activity',
)
