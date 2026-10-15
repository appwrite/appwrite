import { prefixMysqlSqlComment } from '@/lib/mysql-sql'

export function buildMysqlCancelBackendSql(pid: number): string {
  const safePid = Math.floor(Number(pid))
  return prefixMysqlSqlComment(
    `KILL QUERY ${safePid}`,
    'Cancel backend query',
  )
}

export function buildMysqlTerminateBackendSql(pid: number): string {
  const safePid = Math.floor(Number(pid))
  return prefixMysqlSqlComment(
    `KILL CONNECTION ${safePid}`,
    'Terminate backend',
  )
}

export const MYSQL_TERMINATE_IDLE_IN_TRANSACTION_SQL = prefixMysqlSqlComment(
  `
SELECT ID AS terminated, ID AS pid
FROM information_schema.PROCESSLIST
WHERE COMMAND = 'Sleep'
  AND TIME > 0
  AND FALSE
`.trim(),
  'Terminate idle backends (no-op stub on MySQL)',
)
