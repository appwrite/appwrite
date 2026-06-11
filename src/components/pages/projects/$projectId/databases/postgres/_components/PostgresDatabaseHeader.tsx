import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  type PostgresDatabaseTab,
} from '@/lib/postgres-database-routes'

type PostgresDatabaseHeaderProps = {
  databaseTab: PostgresDatabaseTab
}

export function PostgresDatabaseHeader({
  databaseTab,
}: PostgresDatabaseHeaderProps) {
  return (
    <ServiceHeader
      title={POSTGRES_DATABASE_TAB_LABELS[databaseTab]}
      fullWidthBorder
      fullWidth={databaseTab === 'visualizer' || databaseTab === 'monitor'}
    />
  )
}
