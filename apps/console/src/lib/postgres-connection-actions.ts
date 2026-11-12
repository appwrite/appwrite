import { prefixPostgresSqlComment } from '@/lib/postgres-sql'

export function buildPostgresCancelBackendSql(pid: number): string {
  return prefixPostgresSqlComment(
    `SELECT pg_cancel_backend(${pid});`,
    'Cancel backend query',
  )
}

export function buildPostgresTerminateBackendSql(pid: number): string {
  return prefixPostgresSqlComment(
    `SELECT pg_terminate_backend(${pid});`,
    'Terminate backend',
  )
}

export const POSTGRES_TERMINATE_IDLE_IN_TRANSACTION_SQL = prefixPostgresSqlComment(
  `
SELECT pg_terminate_backend(pid) AS terminated, pid
FROM pg_stat_activity
WHERE state = 'idle in transaction'
  AND pid != pg_backend_pid()
`.trim(),
  'Terminate idle backends',
)
