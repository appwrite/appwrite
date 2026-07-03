/**
 * Dedicated-database engine routing + compatibility shims.
 *
 * The console SDK replaced its single `compute` service with per-engine
 * services (`postgresql`, `mysql`, `mongo`). They share the same method surface
 * for the operations we use (list/get/create/getPooler/getStatus/
 * listSpecifications/createExecution), so callers route by the database's
 * `engine` string.
 *
 * Connection credentials are returned inline on `Models.DedicatedDatabase`
 * (`hostname`, `connectionPort`, `connectionUser`, `connectionPassword`,
 * `connectionString`) instead of a separate `getCredentials` endpoint.
 *
 * The same SDK change dropped two endpoints with no replacement:
 * - query EXPLAIN (`createDatabaseQueryExplanation`)
 * - connection listing (`listDatabaseConnections`)
 * The models below stand in for the removed `Models.*` types so the postgres UI
 * keeps compiling; the features themselves are gated (see
 * DEDICATED_FEATURE_UNAVAILABLE). Re-enable by wiring them to a supported API.
 */
import type { Models } from '@appwrite.io/console'
import type { ProjectSdk } from '@/lib/appwrite/sdk'

/** Route a dedicated-DB call to the engine-specific service. Defaults to postgres. */
export function dedicatedEngineService(
  projectSdk: ProjectSdk,
  engine: string | null | undefined,
) {
  const e = (engine ?? '').toLowerCase().trim()
  if (e === 'mongodb' || e === 'mongo') return projectSdk.mongo
  if (e === 'mysql' || e === 'mariadb') return projectSdk.mysql
  return projectSdk.postgresql
}

/**
 * Map internal/console engine identifiers to SDK create/update `engine` param
 * values (e.g. postgres → postgresql).
 */
export function dedicatedDatabaseEngineParam(
  engine: string | null | undefined,
): string | undefined {
  const e = (engine ?? '').toLowerCase().trim()
  if (!e) return undefined
  if (e === 'postgres' || e === 'postgresql') return 'postgresql'
  if (e === 'mysql') return 'mysql'
  if (e === 'mariadb') return 'mariadb'
  if (e === 'mongodb' || e === 'mongo') return 'mongodb'
  return e
}

export const DEDICATED_FEATURE_UNAVAILABLE =
  'This feature is not available on the current console version.'

/** Stand-in for the removed `Models.DedicatedDatabaseQueryExplanation`. */
export type DedicatedDatabaseQueryExplanation = {
  plan: Record<string, any>[]
  raw: string
}

/** Stand-in for the removed `Models.DedicatedDatabaseConnection`. */
export type DedicatedDatabaseConnection = {
  $id: string
  username: string
  database: string
  role: string
  $createdAt: string
}

/** Stand-in for the removed `Models.DedicatedDatabaseConnectionList`. */
export type DedicatedDatabaseConnectionList = {
  total: number
  connections: DedicatedDatabaseConnection[]
}

/** Stand-in for the removed `Models.DedicatedDatabaseCredentials`. */
export type DedicatedDatabaseCredentials = {
  connectionString: string
  host: string
  port: number
  username: string
  password: string
  database: string
  tcpHost: string
  tcpPort: number
  tcpDatabase: string
  ssl: boolean
}

function parseDedicatedConnectionString(connectionString: string): {
  database: string
  ssl: boolean
} {
  try {
    const url = new URL(connectionString)
    const database = url.pathname.replace(/^\//, '') || ''
    const sslMode = url.searchParams.get('sslmode')?.toLowerCase()
    const ssl =
      sslMode === 'require' ||
      sslMode === 'verify-ca' ||
      sslMode === 'verify-full' ||
      url.searchParams.get('ssl') === 'true'
    return { database, ssl }
  } catch {
    return { database: '', ssl: true }
  }
}

/** Map inline connection fields from `Models.DedicatedDatabase.get()`. */
export function mapDedicatedDatabaseCredentials(
  database: Models.DedicatedDatabase,
): DedicatedDatabaseCredentials {
  const parsed = parseDedicatedConnectionString(database.connectionString)
  const catalogName = parsed.database || database.$id

  return {
    connectionString: database.connectionString,
    host: database.hostname,
    port: database.connectionPort,
    username: database.connectionUser,
    password: database.connectionPassword,
    database: catalogName,
    tcpHost: database.hostname,
    tcpPort: database.connectionPort,
    tcpDatabase: catalogName,
    ssl: parsed.ssl,
  }
}
