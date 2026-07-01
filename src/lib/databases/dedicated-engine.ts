/**
 * Dedicated-database engine routing + compatibility shims.
 *
 * The console SDK replaced its single `compute` service with per-engine
 * services (`postgresql`, `mysql`, `mongo`). They share the same method surface
 * for the operations we use (list/get/create/getCredentials/getPooler/
 * getStatus/listSpecifications/createExecution), so callers route by the
 * database's `engine` string.
 *
 * The same SDK change dropped two endpoints with no replacement:
 * - query EXPLAIN (`createDatabaseQueryExplanation`)
 * - connection listing (`listDatabaseConnections`)
 * The models below stand in for the removed `Models.*` types so the postgres UI
 * keeps compiling; the features themselves are gated (see
 * DEDICATED_FEATURE_UNAVAILABLE). Re-enable by wiring them to a supported API.
 */
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
