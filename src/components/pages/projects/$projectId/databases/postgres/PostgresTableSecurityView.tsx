import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import {
  usePostgresTablePolicies,
  usePostgresTableRls,
} from '@/lib/react-query/hooks'
import { postgresNav } from '@/lib/postgres-database-routes'
import { usePostgresTableHeaderSlot } from './_components/PostgresTableHeaderSlotContext'
import { PostgresTableSecurityPanel } from './_components/PostgresTableSecurityPanel'
import { useT } from '@/lib/i18n/translate'

export type PostgresTableSecurityViewProps = {
  databaseId: string
  tableId: string
}

export function PostgresTableSecurityView({
  databaseId,
  tableId,
}: PostgresTableSecurityViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

  const {
    refetch: refetchRls,
    isFetching: rlsFetching,
  } = usePostgresTableRls(projectId, databaseId, tableId)
  const {
    refetch: refetchPolicies,
    isFetching: policiesFetching,
  } = usePostgresTablePolicies(projectId, databaseId, tableId)

  const [searchValue, setSearchValue] = useState('')
  const [createOpen, setCreateOpen] = useState(false)

  useEffect(() => {
    if (canWrite) return
    navigate({
      ...postgresNav({ projectId, databaseId }).table({ tableId }).rows(),
      replace: true,
    })
  }, [canWrite, databaseId, navigate, projectId, tableId])

  const headerProps = useMemo(
    () => ({
      searchPlaceholder: t('Search policies...'),
      searchValue,
      onSearchChange: setSearchValue,
      createLabel: canWrite ? t('Create policy') : undefined,
      onCreate: canWrite ? () => setCreateOpen(true) : undefined,
      createDisabled: !canWrite,
      createDisabledTooltip: canWrite
        ? undefined
        : t("You don't have permission to modify table security."),
      showRefresh: true,
      onRefresh: () => {
        void refetchRls()
        void refetchPolicies()
      },
      isRefreshing: rlsFetching || policiesFetching,
    }),
    [
      canWrite,
      policiesFetching,
      refetchPolicies,
      refetchRls,
      rlsFetching,
      searchValue,
      t,
    ],
  )

  usePostgresTableHeaderSlot(headerProps)

  if (!canWrite) {
    return null
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <PostgresTableSecurityPanel
        databaseId={databaseId}
        tableId={tableId}
        search={searchValue}
        createDialogOpen={createOpen}
        onCreateDialogOpenChange={setCreateOpen}
      />
    </div>
  )
}
