import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { MysqlDatabaseNetworkCard } from '../_components/MysqlDatabaseConfigSettings'
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
      id: 'network',
      search: {
        title: 'Network',
        keywords: ['ip', 'allowlist', 'cidr', 'idle', 'timeout'],
      },
      node: <MysqlDatabaseNetworkCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
