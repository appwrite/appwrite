import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabaseStorageCard } from '../_components/PostgresDatabaseConfigSettings'
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
      id: 'storage',
      search: {
        title: 'Storage',
        keywords: ['autoscaling', 'disk', 'threshold', 'gb'],
      },
      node: <PostgresDatabaseStorageCard {...cardProps} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
