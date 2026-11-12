import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { DatabasePermissionsCard } from './DatabasePermissionsCard'
import { DatabaseSettingsLoading } from './DatabaseSettingsLoading'
import { useDatabaseSettingsPage } from './useDatabaseSettingsPage'

export function View() {
  const { dbKind, database, isLoading } = useDatabaseSettingsPage()

  if (isLoading) return <DatabaseSettingsLoading />
  if (!database) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'permissions',
      search: {
        title: 'Permissions',
        keywords: ['permissions', 'rls', 'row level', 'access', 'security'],
      },
      node: <DatabasePermissionsCard dbKind={dbKind} />,
    },
  ]

  return <SettingsCardsList cards={cards} />
}
