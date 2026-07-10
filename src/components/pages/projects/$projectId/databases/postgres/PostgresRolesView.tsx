import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
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
  const { canWrite, writeTooltip } = useDatabaseTableOperationsAccess()

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
      createDisabledTooltip: writeTooltip,
      showRefresh: true,
      onRefresh: () => void refetch(),
      isRefreshing: isFetching,
    }),
    [canWrite, isFetching, refetch, searchValue, t, writeTooltip],
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
