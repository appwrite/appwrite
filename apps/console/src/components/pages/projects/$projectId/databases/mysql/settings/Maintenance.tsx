import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { MysqlDatabaseMaintenanceCard } from '../_components/MysqlDatabaseMaintenanceCard'
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
      id: 'maintenance',
      search: {
        title: 'Maintenance window',
        keywords: ['window', 'utc', 'day', 'hour', 'upgrade', 'weekly', 'am', 'pm'],
      },
      node: <MysqlDatabaseMaintenanceCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
