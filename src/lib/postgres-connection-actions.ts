export function buildPostgresCancelBackendSql(pid: number): string {
  return `SELECT pg_cancel_backend(${pid});`
}

export function buildPostgresTerminateBackendSql(pid: number): string {
  return `SELECT pg_terminate_backend(${pid});`
}

export const POSTGRES_TERMINATE_IDLE_IN_TRANSACTION_SQL = `
SELECT pg_terminate_backend(pid) AS terminated, pid
FROM pg_stat_activity
WHERE state = 'idle in transaction'
  AND pid != pg_backend_pid()
`.trim()
