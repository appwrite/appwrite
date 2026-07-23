import type { Models } from '@appwrite.io/console'
import type { DedicatedReplicationSource } from '@/lib/databases/dedicated-replication'

export type PostgresDatabaseSettingsCardProps = {
  projectId: string
  databaseId: string
  database: Models.DedicatedDatabase
  canWrite: boolean
  /**
   * Where getReplicas / failover / HA updates are routed.
   * Defaults to the native PostgreSQL engine.
   */
  replicationSource?: DedicatedReplicationSource
  /**
   * Engine used for HA updates that require engine APIs (replicas + syncMode).
   * Defaults to `database.engine` or postgresql.
   */
  haEngine?: string
}
