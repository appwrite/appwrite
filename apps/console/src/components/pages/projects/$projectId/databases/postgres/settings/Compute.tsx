import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { useScrollToCard } from '@/hooks/use-scroll-to-card'
import { PostgresDatabaseComputeTierCard } from '../_components/PostgresDatabaseGeneralSettings'
import { usePostgresDatabaseSettingsPage } from './usePostgresDatabaseSettingsPage'
import { PostgresSettingsLoading } from './PostgresSettingsLoading'

export function View() {
  const { projectId, databaseId, database, canWrite, isLoading } =
    usePostgresDatabaseSettingsPage()
  useScrollToCard()

  if (isLoading) return <PostgresSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'compute-tier',
      search: {
        title: 'Compute tier',
        keywords: ['tier', 'cpu', 'memory', 'upgrade', 'specification', 'price'],
      },
      node: <PostgresDatabaseComputeTierCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
