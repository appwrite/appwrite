import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { MysqlDatabasePitrCard } from '../_components/MysqlDatabaseConfigSettings'
import { DedicatedDatabasePitrRestoreCard } from '../../_components/RestorePitr'
import { useMysqlDatabaseSettingsPage } from './useMysqlDatabaseSettingsPage'
import { MysqlSettingsLoading } from './MysqlSettingsLoading'
import { useConsoleProfile } from '@/hooks/use-console-profile'

export function View() {
  const { projectId, databaseId, database, canWrite, isLoading } =
    useMysqlDatabaseSettingsPage()
  const { features } = useConsoleProfile()

  if (isLoading) return <MysqlSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'pitr',
      search: {
        title: 'Point-in-time recovery (PITR)',
        keywords: ['pitr', 'retention', 'restore', 'recovery', 'point in time'],
      },
      node: <MysqlDatabasePitrCard {...cardProps} />,
    },
    ...(features.databasePitrRestore
      ? [
          {
            id: 'pitr-restore',
            search: {
              title: 'Restore to a point in time',
              keywords: [
                'pitr',
                'restore',
                'recovery',
                'point in time',
                'timestamp',
              ],
            },
            node: (
              <DedicatedDatabasePitrRestoreCard {...cardProps} engine="mysql" />
            ),
          } satisfies SettingsCardItem,
        ]
      : []),
  ]

  return <SettingsCardsList cards={cards} />
}
