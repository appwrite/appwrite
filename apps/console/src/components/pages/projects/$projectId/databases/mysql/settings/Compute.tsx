import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import { MysqlDatabaseComputeTierCard } from '../_components/MysqlDatabaseGeneralSettings'
import { useMysqlDatabaseSettingsPage } from './useMysqlDatabaseSettingsPage'
import { MysqlSettingsLoading } from './MysqlSettingsLoading'

export function View() {
  const { projectId, databaseId, database, canWrite, isLoading } =
    useMysqlDatabaseSettingsPage()
  useScrollToCard()

  if (isLoading) return <MysqlSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'compute-tier',
      search: {
        title: 'Compute tier',
        keywords: ['tier', 'cpu', 'memory', 'upgrade', 'specification', 'price'],
      },
      node: <MysqlDatabaseComputeTierCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
