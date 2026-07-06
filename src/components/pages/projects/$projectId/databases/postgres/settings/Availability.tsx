import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabaseHaCard } from '../_components/PostgresDatabaseConfigSettings'
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
      id: 'high-availability',
      search: {
        title: 'High availability',
        keywords: ['replica', 'replicas', 'sync', 'failover', 'ha', 'topology', 'cluster'],
      },
      node: <PostgresDatabaseHaCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
