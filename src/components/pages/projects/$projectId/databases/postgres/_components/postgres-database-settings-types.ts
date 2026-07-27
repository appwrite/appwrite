import type { Models } from '@appwrite.io/console'
import type { DedicatedReplicationSource } from '@/lib/databases/dedicated-replication'

export type PostgresDatabaseSettingsCardProps = {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  canWrite: boolean
  /**
   * Where getReplicas / failover / HA updates are routed.
   * Product DBs must use `{ type: 'product', api }`; native DBs use the engine.
   * Defaults to the native PostgreSQL engine.
   */
  replicationSource?: DedicatedReplicationSource
  /**
   * Backing engine hint for engine-only UI (e.g. Postgres connection metrics).
   * Not used for product-owned HA mutations. Defaults to `database.engine`.
   */
  haEngine?: string
}
