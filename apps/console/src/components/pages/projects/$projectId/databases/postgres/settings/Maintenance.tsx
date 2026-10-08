import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabaseMaintenanceCard } from '../_components/PostgresDatabaseMaintenanceCard'
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
      id: 'maintenance',
      search: {
        title: 'Maintenance window',
        keywords: ['window', 'utc', 'day', 'hour', 'upgrade', 'weekly', 'am', 'pm'],
      },
      node: <PostgresDatabaseMaintenanceCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
