import type { DedicatedDatabaseCredentials } from '@/lib/databases/dedicated-engine'

const MASKED_PASSWORD = '••••••••'

/**
 * Returns a DSN suitable for display, with the password segment hidden.
 * The original connection string should still be used for copy/actions.
 */
export function maskMysqlConnectionStringPassword(
  connectionString: string,
  password?: string,
): string {
  if (password) {
    if (connectionString.includes(`:${password}@`)) {
      return connectionString.replace(`:${password}@`, `:${MASKED_PASSWORD}@`)
    }

    const encodedPassword = encodeURIComponent(password)
    if (
      encodedPassword !== password &&
      connectionString.includes(`:${encodedPassword}@`)
    ) {
      return connectionString.replace(
        `:${encodedPassword}@`,
        `:${MASKED_PASSWORD}@`,
      )
    }
  }

  try {
    const parsed = new URL(connectionString)
    if (!parsed.password) return connectionString
    parsed.password = MASKED_PASSWORD
    return parsed.toString()
  } catch {
    return connectionString.replace(
      /^(mysql(?:ql)?(?:\+[\w-]+)?:\/\/[^:/@\s]+:)([^@\s/]+)(@)/i,
      `$1${MASKED_PASSWORD}$3`,
    )
  }
}

type MysqlConnectionStringCredentials = Pick<
  DedicatedDatabaseCredentials,
  | 'connectionString'
  | 'host'
  | 'port'
  | 'username'
  | 'password'
  | 'database'
  | 'tcpHost'
  | 'tcpPort'
  | 'tcpDatabase'
  | 'ssl'
>

/**
 * Builds a direct TCP connection string for migrations and schema tools.
 * Preserves SSL query params from the primary connection string when present.
 */
export function buildMysqlDirectConnectionString(
  credentials: MysqlConnectionStringCredentials,
): string | null {
  if (!credentials.connectionString) return null

  const host = credentials.tcpHost || credentials.host
  const port = credentials.tcpPort || credentials.port
  const database = credentials.tcpDatabase || credentials.database

  if (!host || !port || !database) return null

  try {
    const parsed = new URL(credentials.connectionString)
    parsed.username = credentials.username
    parsed.password = credentials.password
    parsed.hostname = host
    parsed.port = String(port)
    parsed.pathname = `/${database}`
    return parsed.toString()
  } catch {
    const sslQuery = credentials.ssl ? '?sslmode=require' : ''
    return `mysqlql://${encodeURIComponent(credentials.username)}:${encodeURIComponent(credentials.password)}@${host}:${String(port)}/${database}${sslQuery}`
  }
}
