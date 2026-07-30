import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useOrganizationScopes,
  useProject,
  useProjectDatabase,
  useProjectTables,
} from '@/lib/react-query/hooks'
import { isDatabaseRouteKind, type DatabaseRouteKind } from '@/lib/database-routes'
import { useParams } from '@tanstack/react-router'
import { useDatabaseAdminOperationsAccess } from '../_components/DatabaseOperationsLockContext'

export function useDatabaseSettingsPage() {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const dbKind = (
    isDatabaseRouteKind(params.dbKind ?? '')
      ? params.dbKind
      : 'tablesdb'
  ) as DatabaseRouteKind

  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { database, isLoading } = useProjectDatabase(
    projectId,
    databaseId,
    dbKind,
  )
  const { total: containersTotal } = useProjectTables(
    projectId,
    databaseId,
    dbKind,
    0,
    1,
    undefined,
    'asc',
    '$createdAt',
  )
  const { canWrite } = useDatabaseAdminOperationsAccess()

  return {
    projectId,
    databaseId,
    dbKind,
    database,
    containersTotal: containersTotal ?? 0,
    teamId: project?.teamId,
    canWrite,
    isLoading,
    permissionCanWrite: canCreateDatabase(access, features),
  }
}
