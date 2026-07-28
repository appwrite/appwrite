/**
 * Dedicated-database engine routing.
 *
 * The console SDK uses per-engine services (`postgresql`, `mysql`, `mongo`).
 * They share the same method surface for the operations we use
 * (list/get/create/getPooler/getStatus/listSpecifications/createExecution),
 * so callers route by the database's `engine` string. Engine is selected by
 * which service is called; create/update no longer accept an `engine` param.
 *
 * Product-owned dedicated DBs are created via the product APIs
 * (`tablesDB` / `documentsDB` / `vectorsDB`) with a dedicated `specification`
 * and share the same database ID on the backing engine. Native (raw) DBs are
 * created via the engine services; the API sets `api` from the engine
 * (`postgresql` / `mysql` / `mongodb`).
 *
 * Never mutate product-owned databases through these engine services
 * (create/update/delete/replicas/HA/specification/pitr). Product IDs are
 * reached only through their product APIs (`tablesDB` / `documentsDB` /
 * `vectorsDB`); use `dedicatedDatabaseService` with a product source for
 * HA/replicas/listSpecifications. Specification changes on TablesDB use
 * `tablesDB.createMigration`, not mysql.update.
 *
 * Each product and engine exposes its own `listSpecifications()`. Do not use
 * postgres (or any single engine) as a stand-in for other database types.
 *
 * Connection credentials are returned inline on `Models.DedicatedDatabase`
 * (`hostname`, `connectionPort`, `connectionUser`, `connectionPassword`,
 * `connectionString`) instead of a separate `getCredentials` endpoint.
 *
 * PITR is controlled via the `pitr` create/update param (response field
 * `pitr`; `backupEnabled` is a separate response-only flag).
 *
 * Connection listing uses `postgresql.createExecution` + `pg_stat_activity`
 * (see `fetchPostgresDatabaseConnections` / `fetchPostgresActiveConnections`).
 * Query EXPLAIN runs via `postgresql.createExecution` with `EXPLAIN (FORMAT JSON)`.
 *
 * Note: `mongo` has no `createExecution` or `getPooler`.
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
