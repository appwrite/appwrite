import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabasePoolerCard } from '../_components/PostgresDatabaseConfigSettings'
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
      id: 'pooler',
      search: {
        title: 'Connection pooler',
        keywords: ['pool', 'pooler', 'transaction', 'session', 'splitting'],
      },
      node: <PostgresDatabasePoolerCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
