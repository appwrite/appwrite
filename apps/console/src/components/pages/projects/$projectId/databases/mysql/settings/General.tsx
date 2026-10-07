import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { MysqlDatabaseDangerZoneCard } from '../_components/MysqlDatabaseDangerZoneCard'
import {
  MysqlDatabaseDetailsCard,
  MysqlDatabaseNameCard,
} from '../_components/MysqlDatabaseGeneralSettings'
import { useMysqlDatabaseSettingsPage } from './useMysqlDatabaseSettingsPage'
import { MysqlSettingsLoading } from './MysqlSettingsLoading'

export function View() {
  const {
    projectId,
    databaseId,
    database,
    canWrite,
    permissionCanWrite,
    isLoading,
  } = useMysqlDatabaseSettingsPage()

  if (isLoading) return <MysqlSettingsLoading />
  if (!database) return null

  const cardProps = { projectId, databaseId, database, canWrite }

  const cards: SettingsCardItem[] = [
    {
      id: 'name',
      search: {
        title: 'Name',
        keywords: ['rename', 'display', 'database name'],
      },
      node: <MysqlDatabaseNameCard {...cardProps} />,
    },
    {
      id: 'details',
      search: {
        title: 'Details',
        keywords: ['id', 'created', 'updated', 'status', 'paused', 'version'],
      },
      node: <MysqlDatabaseDetailsCard {...cardProps} />,
    },
    {
      id: 'delete',
      search: {
        title: 'Delete database',
        keywords: ['delete', 'remove', 'destroy', 'danger'],
      },
      node: (
        <MysqlDatabaseDangerZoneCard
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
