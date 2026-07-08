import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  PostgresDatabaseReplicasCard,
  PostgresDatabaseSyncModeCard,
} from '../_components/PostgresDatabaseConfigSettings'
import { usePostgresDatabaseSettingsPage } from './usePostgresDatabaseSettingsPage'
import { PostgresSettingsLoading } from './PostgresSettingsLoading'

export function View() {
  const { projectId, databaseId, database, canWrite, isLoading } =
    usePostgresDatabaseSettingsPage()

  if (isLoading) return <PostgresSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'replicas',
      search: {
        title: 'Read replicas',
        keywords: ['replica', 'replicas', 'failover', 'ha', 'topology', 'cluster'],
      },
      node: <PostgresDatabaseReplicasCard {...cardProps} />,
    },
    {
      id: 'sync-mode',
      search: {
        title: 'Sync mode',
        keywords: ['sync', 'async', 'synchronous', 'quorum', 'replication'],
      },
      node: <PostgresDatabaseSyncModeCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
