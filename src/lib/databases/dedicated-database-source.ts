/**
 * Product vs engine service routing for dedicated-database operations.
 *
 * Product-owned DBs (TablesDB / DocumentsDB / VectorsDB) are reached only
 * through their product APIs. Native DBs use postgresql / mysql / mongo.
 * Shared helpers (listSpecifications, getReplicas, createFailover, HA update)
 * must pick the owning service, never a stand-in like "always postgres" or
 * "TablesDB → mysql".
 */
import type { ProjectSdk } from '@/lib/appwrite/sdk'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import { DatabaseType } from '@/lib/databases/database-type'
import type { DatabaseRouteKind } from '@/lib/database-routes'

export type DedicatedDatabaseProductApi =
  | 'tablesdb'
  | 'documentsdb'
  | 'vectorsdb'

export type DedicatedDatabaseSource =
  | { type: 'engine'; engine: string }
  | { type: 'product'; api: DedicatedDatabaseProductApi }

/** @deprecated Prefer DedicatedDatabaseSource */
export type DedicatedReplicationSource = DedicatedDatabaseSource

/** @deprecated Prefer DedicatedDatabaseProductApi */
export type DedicatedReplicationProductApi = DedicatedDatabaseProductApi

export function dedicatedDatabaseSourceFromRouteKind(
  dbKind: DatabaseRouteKind,
): DedicatedDatabaseSource {
  return { type: 'product', api: dbKind }
}

export function dedicatedDatabaseSourceFromDatabaseType(
  backend: DatabaseType,
): DedicatedDatabaseSource {
  if (backend === DatabaseType.Documentsdb) {
    return { type: 'product', api: 'documentsdb' }
  }
  if (backend === DatabaseType.Vectorsdb) {
    return { type: 'product', api: 'vectorsdb' }
  }
  return { type: 'product', api: 'tablesdb' }
}

export function dedicatedDatabaseSourceFromEngine(
  engine: string,
): DedicatedDatabaseSource {
  return { type: 'engine', engine }
}

/** Native PostgreSQL settings / monitor screens. */
export const POSTGRES_DATABASE_SPECS_SOURCE: DedicatedDatabaseSource =
  dedicatedDatabaseSourceFromEngine('postgresql')

export function dedicatedDatabaseSourceKey(
  source: DedicatedDatabaseSource,
): string {
  return source.type === 'product'
    ? `product:${source.api}`
    : `engine:${(source.engine || 'postgresql').toLowerCase()}`
}

/**
 * Service for operations that exist on both product and engine SDKs
 * (listSpecifications, getReplicas, createFailover, update with replicas, …).
 */
export function dedicatedDatabaseService(
  projectSdk: ProjectSdk,
  source: DedicatedDatabaseSource,
) {
  if (source.type === 'product') {
    if (source.api === 'documentsdb') return projectSdk.documentsDB
    if (source.api === 'vectorsdb') return projectSdk.vectorsDB
    return projectSdk.tablesDB
  }
  return dedicatedEngineService(projectSdk, source.engine)
}

// Compatibility aliases used by replication call sites.
export const dedicatedReplicationSourceFromRouteKind =
  dedicatedDatabaseSourceFromRouteKind
export const dedicatedReplicationSourceKey = dedicatedDatabaseSourceKey
export const dedicatedReplicationService = dedicatedDatabaseService

/** Native MySQL settings / monitor screens. */
export const MYSQL_DATABASE_SPECS_SOURCE: DedicatedDatabaseSource =
  dedicatedDatabaseSourceFromEngine('mysql')
