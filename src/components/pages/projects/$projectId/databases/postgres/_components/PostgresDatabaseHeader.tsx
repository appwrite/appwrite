import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'
import { PostgresConnectHeaderButton } from './PostgresConnectHeaderButton'
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
  const titleLabel = POSTGRES_DATABASE_TAB_LABELS[databaseTab]

  return (
    <ServiceHeader
      title={
        databaseTab === 'connections' ? (
          <span className="inline-flex min-w-0 items-baseline gap-2">
            <span className="truncate">{titleLabel}</span>
            <PostgresConnectionsHeaderLimit
              projectId={projectId}
              databaseId={databaseId}
            />
          </span>
        ) : (
          titleLabel
        )
      }
      fullWidthBorder
      fullWidth={
        databaseTab === 'sql' ||
        databaseTab === 'visualizer' ||
        databaseTab === 'monitor' ||
        databaseTab === 'connections'
      }
      titleRightContent={<PostgresConnectHeaderButton />}
    />
  )
}
