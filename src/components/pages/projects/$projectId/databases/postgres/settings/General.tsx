import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { PostgresDatabaseDangerZoneCard } from '../_components/PostgresDatabaseDangerZoneCard'
import {
  PostgresDatabaseDetailsCard,
  PostgresDatabaseNameCard,
} from '../_components/PostgresDatabaseGeneralSettings'
import { usePostgresDatabaseSettingsPage } from './usePostgresDatabaseSettingsPage'
import { PostgresSettingsLoading } from './PostgresSettingsLoading'

export function View() {
  const {
    projectId,
    databaseId,
    database,
    canWrite,
    permissionCanWrite,
    isLoading,
  } = usePostgresDatabaseSettingsPage()

  if (isLoading) return <PostgresSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'name',
      search: {
        title: 'Name',
        keywords: ['rename', 'display', 'database name'],
      },
      node: <PostgresDatabaseNameCard {...cardProps} />,
    },
    {
      id: 'details',
      search: {
        title: 'Details',
        keywords: ['id', 'created', 'updated', 'status', 'paused', 'version'],
      },
      node: <PostgresDatabaseDetailsCard {...cardProps} />,
    },
    {
      id: 'delete',
      search: {
        title: 'Delete database',
        keywords: ['delete', 'remove', 'destroy', 'danger'],
      },
      node: (
        <PostgresDatabaseDangerZoneCard
          projectId={projectId}
          database={database}
          // Keep delete available when ops are locked (e.g. failed create).
          canWrite={permissionCanWrite}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
