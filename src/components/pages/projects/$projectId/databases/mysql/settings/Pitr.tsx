import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { MysqlDatabasePitrCard } from '../_components/MysqlDatabaseConfigSettings'
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
      id: 'pitr',
      search: {
        title: 'Point-in-time recovery (PITR)',
        keywords: ['pitr', 'retention', 'restore', 'recovery', 'point in time'],
      },
      node: <MysqlDatabasePitrCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
