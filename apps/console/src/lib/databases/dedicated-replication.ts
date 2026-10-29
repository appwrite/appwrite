/**
 * Replication routing for dedicated databases.
 * Re-exports the shared product|engine service router.
 */
export {
  dedicatedDatabaseService as dedicatedReplicationService,
  dedicatedDatabaseSourceFromRouteKind as dedicatedReplicationSourceFromRouteKind,
  dedicatedDatabaseSourceKey as dedicatedReplicationSourceKey,
  type DedicatedDatabaseProductApi as DedicatedReplicationProductApi,
  type DedicatedDatabaseSource as DedicatedReplicationSource,
} from '@/lib/databases/dedicated-database-source'
