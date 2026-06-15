import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { PostgresConnectionsHeaderLimit } from './PostgresConnectionsHeaderLimit'

type PostgresDatabaseHeaderProps = {
  projectId: string
  databaseId: string
  databaseTab: PostgresDatabaseTab
}

export function PostgresDatabaseHeader({
  projectId,
  databaseId,
  databaseTab,
}: PostgresDatabaseHeaderProps) {
  return (
    <ServiceHeader
      title={POSTGRES_DATABASE_TAB_LABELS[databaseTab]}
      fullWidthBorder
      fullWidth={
        databaseTab === 'sql' ||
        databaseTab === 'visualizer' ||
        databaseTab === 'monitor' ||
        databaseTab === 'connections'
      }
      titleRightContent={
        databaseTab === 'connections' ? (
          <PostgresConnectionsHeaderLimit
            projectId={projectId}
            databaseId={databaseId}
          />
        ) : undefined
      }
    />
  )
}
