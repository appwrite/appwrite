import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { DatabaseDangerZoneCard } from './DatabaseDangerZoneCard'
import { DatabaseDetailsCard } from './DatabaseDetailsCard'
import { DatabaseNameCard } from './DatabaseNameCard'
import { DatabaseSettingsLoading } from './DatabaseSettingsLoading'
import { useDatabaseSettingsPage } from './useDatabaseSettingsPage'

export function View() {
  const {
    projectId,
    databaseId,
    dbKind,
    database,
    containersTotal,
    canWrite,
    permissionCanWrite,
    isLoading,
  } = useDatabaseSettingsPage()

  if (isLoading) return <DatabaseSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, dbKind, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'name',
      search: {
        title: 'Name',
        keywords: ['rename', 'display', 'database name'],
      },
      node: <DatabaseNameCard {...cardProps} />,
    },
    {
      id: 'details',
      search: {
        title: 'Details',
        keywords: ['id', 'created', 'updated', 'enabled', 'disabled', 'status'],
      },
      node: <DatabaseDetailsCard {...cardProps} />,
    },
    {
      id: 'delete',
      search: {
        title: 'Delete database',
        keywords: ['delete', 'remove', 'destroy', 'danger'],
      },
      node: (
        <DatabaseDangerZoneCard
          projectId={projectId}
          databaseId={databaseId}
          database={database}
          dbKind={dbKind}
          containersTotal={containersTotal}
          // Keep delete available when ops are locked (e.g. failed create).
          canWrite={permissionCanWrite}
        />
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
