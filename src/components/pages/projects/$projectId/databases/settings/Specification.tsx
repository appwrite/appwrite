import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import { DatabaseSpecificationCard } from './DatabaseSpecificationCard'
import { DatabaseSettingsLoading } from './DatabaseSettingsLoading'
import { useDatabaseSettingsPage } from './useDatabaseSettingsPage'

export function View() {
  const { projectId, database, isLoading } = useDatabaseSettingsPage()
  useScrollToCard()

  if (isLoading) return <DatabaseSettingsLoading />
  if (!database) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'specification',
      search: {
        title: 'Specification',
        keywords: [
          'tier',
          'cpu',
          'memory',
          'upgrade',
          'specification',
          'price',
          'serverless',
          'connections',
        ],
      },
      node: <DatabaseSpecificationCard projectId={projectId} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
