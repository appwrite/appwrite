import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { usePostgresRoles } from '@/lib/react-query/hooks'
import { usePostgresDatabaseHeaderSlot } from './_components/PostgresDatabaseHeaderSlotContext'
import { PostgresRolesPanel } from './_components/PostgresRolesPanel'
import { useT } from '@/lib/i18n/translate'

type PostgresRolesViewProps = {
  databaseId: string
}

export function PostgresRolesView({ databaseId }: PostgresRolesViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

  const [searchValue, setSearchValue] = useState('')
  const [createOpen, setCreateOpen] = useState(false)

  const { refetch, isFetching } = usePostgresRoles(projectId, databaseId)

  const headerSlot = useMemo(
    () => ({
      searchPlaceholder: t('Search roles...'),
      searchValue,
      onSearchChange: setSearchValue,
      createLabel: canWrite ? t('Create role') : undefined,
      onCreate: canWrite ? () => setCreateOpen(true) : undefined,
      createDisabled: !canWrite,
      createDisabledTooltip: canWrite
        ? undefined
        : t("You don't have permission to modify table structure."),
      showRefresh: true,
      onRefresh: () => void refetch(),
      isRefreshing: isFetching,
    }),
    [canWrite, isFetching, refetch, searchValue, t],
  )

  usePostgresDatabaseHeaderSlot(headerSlot)

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <PostgresRolesPanel
        databaseId={databaseId}
        searchValue={searchValue}
        createOpen={createOpen}
        onCreateOpenChange={setCreateOpen}
      />
    </div>
  )
}
