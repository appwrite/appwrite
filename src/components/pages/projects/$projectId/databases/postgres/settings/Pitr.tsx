import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabasePitrCard } from '../_components/PostgresDatabaseConfigSettings'
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
      id: 'pitr',
      search: {
        title: 'Point-in-time recovery (PITR)',
        keywords: ['pitr', 'retention', 'restore', 'recovery', 'point in time'],
      },
      node: <PostgresDatabasePitrCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
