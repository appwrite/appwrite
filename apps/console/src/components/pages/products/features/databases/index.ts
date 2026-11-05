import type { ComponentType } from 'react'
import { DatabasesBackupsVisual } from '@/components/pages/products/features/databases/DatabasesBackupsVisual'
import { DatabasesPermissionsVisual } from '@/components/pages/products/features/databases/DatabasesPermissionsVisual'
import { DatabasesQueriesVisual } from '@/components/pages/products/features/databases/DatabasesQueriesVisual'
import { DatabasesReplicationVisual } from '@/components/pages/products/features/databases/DatabasesReplicationVisual'
import { DatabasesServerlessVisual } from '@/components/pages/products/features/databases/DatabasesServerlessVisual'
import { DatabasesSqlVisual } from '@/components/pages/products/features/databases/DatabasesSqlVisual'

export { DatabasesEnginesCatalog } from '@/components/pages/products/features/databases/DatabasesEnginesCatalog'
export { DatabasesOrmCatalog } from '@/components/pages/products/features/databases/DatabasesOrmCatalog'

export const DATABASES_FEATURE_VISUALS: Record<string, ComponentType> = {
  'serverless-dedicated': DatabasesServerlessVisual,
  replication: DatabasesReplicationVisual,
  backups: DatabasesBackupsVisual,
  permissions: DatabasesPermissionsVisual,
  queries: DatabasesQueriesVisual,
  sql: DatabasesSqlVisual,
}
