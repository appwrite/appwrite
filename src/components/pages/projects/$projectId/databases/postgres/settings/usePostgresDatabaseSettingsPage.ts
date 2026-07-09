import { canCreateDatabase } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useOrganizationScopes,
  usePostgresDatabase,
  useProject,
} from '@/lib/react-query/hooks'
import { useParams } from '@tanstack/react-router'
import { usePostgresAdminWriteAccess } from '../_components/PostgresDatabaseWriteLockContext'

export function usePostgresDatabaseSettingsPage() {
  const { projectId, databaseId } = useParams({ strict: false }) as {
    projectId: string
    databaseId: string
  }
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const { database, isLoading } = usePostgresDatabase(projectId, databaseId)
  const { canWrite } = usePostgresAdminWriteAccess()

  return {
    projectId,
    databaseId,
    database,
    canWrite,
    isLoading,
    permissionCanWrite: canCreateDatabase(access, features),
  }
}
