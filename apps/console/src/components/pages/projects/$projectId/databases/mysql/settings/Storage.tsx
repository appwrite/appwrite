import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { MysqlDatabaseStorageCard } from '../_components/MysqlDatabaseConfigSettings'
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
      id: 'storage',
      search: {
        title: 'Storage',
        keywords: ['autoscaling', 'disk', 'threshold', 'gb'],
      },
      node: <MysqlDatabaseStorageCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
