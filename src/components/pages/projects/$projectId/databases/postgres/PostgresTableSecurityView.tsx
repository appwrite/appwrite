import { useEffect } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { postgresNav } from '@/lib/postgres-database-routes'
import { usePostgresTableHeaderSlot } from './_components/PostgresTableHeaderSlotContext'
import { PostgresTableSecurityPanel } from './_components/PostgresTableSecurityPanel'

export type PostgresTableSecurityViewProps = {
  databaseId: string
  tableId: string
}

export function PostgresTableSecurityView({
  databaseId,
  tableId,
}: PostgresTableSecurityViewProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

  usePostgresTableHeaderSlot({})

  useEffect(() => {
    if (canWrite) return
    navigate({
      ...postgresNav({ projectId, databaseId }).table({ tableId }).rows(),
      replace: true,
    })
  }, [canWrite, databaseId, navigate, projectId, tableId])

  if (!canWrite) {
    return null
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <PostgresTableSecurityPanel databaseId={databaseId} tableId={tableId} />
    </div>
  )
}
