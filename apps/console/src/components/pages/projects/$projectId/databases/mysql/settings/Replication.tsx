import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  MysqlDatabaseReplicasCard,
  MysqlDatabaseSyncModeCard,
} from '../_components/MysqlDatabaseConfigSettings'
import { MysqlDatabasePrimaryCard } from '../_components/MysqlDatabasePrimaryCard'
import { useMysqlDatabaseSettingsPage } from './useMysqlDatabaseSettingsPage'
import { MysqlSettingsLoading } from './MysqlSettingsLoading'

export function View() {
  const { projectId, databaseId, database, canWrite, isLoading } =
    useMysqlDatabaseSettingsPage()

  if (isLoading) return <MysqlSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'replicas',
      search: {
        title: 'Read replicas',
        keywords: ['replica', 'replicas', 'failover', 'ha', 'topology', 'cluster'],
      },
      node: <MysqlDatabaseReplicasCard {...cardProps} />,
    },
    ...(database.replicas && database.replicas > 0
      ? [
          {
            id: 'primary',
            search: {
              title: 'Primary instance',
              keywords: [
                'primary',
                'main',
                'leader',
                'failover',
                'promote',
                'promotion',
              ],
            },
            node: <MysqlDatabasePrimaryCard {...cardProps} />,
          } satisfies SettingsCardItem,
        ]
      : []),
    {
      id: 'sync-mode',
      search: {
        title: 'Sync mode',
        keywords: ['sync', 'async', 'synchronous', 'quorum', 'replication'],
      },
      node: <MysqlDatabaseSyncModeCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
