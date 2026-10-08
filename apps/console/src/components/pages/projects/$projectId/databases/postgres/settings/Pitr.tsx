import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabasePitrCard } from '../_components/PostgresDatabaseConfigSettings'
import { DedicatedDatabasePitrRestoreCard } from '../../_components/RestorePitr'
import { usePostgresDatabaseSettingsPage } from './usePostgresDatabaseSettingsPage'
import { PostgresSettingsLoading } from './PostgresSettingsLoading'
import { useConsoleProfile } from '@/hooks/use-console-profile'

export function View() {
  const { projectId, databaseId, database, canWrite, isLoading } =
    usePostgresDatabaseSettingsPage()
  const { features } = useConsoleProfile()

  if (isLoading) return <PostgresSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'pitr',
      search: {
        title: 'Point-in-time recovery (PITR)',
        keywords: ['pitr', 'retention', 'restore', 'recovery', 'point in time'],
      },
      node: <PostgresDatabasePitrCard {...cardProps} />,
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
              <DedicatedDatabasePitrRestoreCard
                {...cardProps}
                engine="postgresql"
              />
            ),
          } satisfies SettingsCardItem,
        ]
      : []),
  ]

  return <SettingsCardsList cards={cards} />
}
