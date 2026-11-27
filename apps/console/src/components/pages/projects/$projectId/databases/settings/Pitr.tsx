import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import { hasDedicatedSpecification } from '@/lib/databases/database-compute'
import { useT } from '@/lib/i18n/translate'
import { DatabasePitrCard } from './DatabasePitrCard'
import { DatabaseSettingsLoading } from './DatabaseSettingsLoading'
import type { ProjectDatabaseDetail } from './types'
import { useDatabaseSettingsPage } from './useDatabaseSettingsPage'

export function View() {
  const t = useT()
  const { projectId, databaseId, dbKind, database, canWrite, isLoading } =
    useDatabaseSettingsPage()
  useScrollToCard()

  if (isLoading) return <DatabaseSettingsLoading />
  if (!database) return null

  const detail: ProjectDatabaseDetail = database
  if (!hasDedicatedSpecification(detail.specification)) {
    return (
      <EmptyState
        title={t('Point-in-time recovery is available on dedicated databases')}
        description={t(
          'Upgrade this database to a dedicated specification to restore it to any moment within a retention window.',
        )}
      />
    )
  }

  const cards: SettingsCardItem[] = [
    {
      id: 'pitr',
      search: {
        title: 'Point-in-time recovery (PITR)',
        keywords: ['pitr', 'retention', 'restore', 'recovery', 'point in time'],
      },
      node: (
        <DatabasePitrCard
          projectId={projectId}
          databaseId={databaseId}
          dbKind={dbKind}
          database={detail}
          canWrite={canWrite}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
