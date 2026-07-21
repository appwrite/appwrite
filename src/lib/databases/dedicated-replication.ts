import type { ProjectSdk } from '@/lib/appwrite/sdk'
import { dedicatedEngineService } from '@/lib/databases/dedicated-engine'
import type { DatabaseRouteKind } from '@/lib/database-routes'

export type DedicatedReplicationProductApi =
  | 'tablesdb'
  | 'documentsdb'
  | 'vectorsdb'

/**
 * Where getReplicas / createFailover / HA updates are routed.
 * Product-owned dedicated DBs use the product SDK; native DBs use the engine.
 */
export type DedicatedReplicationSource =
  | { type: 'engine'; engine: string }
  | { type: 'product'; api: DedicatedReplicationProductApi }

export function dedicatedReplicationSourceFromRouteKind(
  dbKind: DatabaseRouteKind,
): DedicatedReplicationSource {
  return { type: 'product', api: dbKind }
}

export function dedicatedReplicationSourceKey(
  source: DedicatedReplicationSource,
): string {
  return source.type === 'product'
    ? `product:${source.api}`
    : `engine:${(source.engine || 'postgresql').toLowerCase()}`
}

/** Service exposing getReplicas + createFailover for the given source. */
export function dedicatedReplicationService(
  projectSdk: ProjectSdk,
  source: DedicatedReplicationSource,
) {
  if (source.type === 'product') {
    if (source.api === 'documentsdb') return projectSdk.documentsDB
    if (source.api === 'vectorsdb') return projectSdk.vectorsDB
    return projectSdk.tablesDB
  }
  return dedicatedEngineService(projectSdk, source.engine)
}
