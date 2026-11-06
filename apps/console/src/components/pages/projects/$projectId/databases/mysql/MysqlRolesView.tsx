import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import { useMysqlRoles } from '@/lib/react-query/hooks'
import { useMysqlDatabaseHeaderSlot } from './_components/MysqlDatabaseHeaderSlotContext'
import { MysqlRolesPanel } from './_components/MysqlRolesPanel'
import { useT } from '@/lib/i18n/translate'

type MysqlRolesViewProps = {
  databaseId: string
}

export function MysqlRolesView({ databaseId }: MysqlRolesViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { canWrite, writeTooltip } = useDatabaseTableOperationsAccess()

  const [searchValue, setSearchValue] = useState('')
  const [createOpen, setCreateOpen] = useState(false)

  const { refetch, isFetching } = useMysqlRoles(projectId, databaseId)

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

  useMysqlDatabaseHeaderSlot(headerSlot)

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <MysqlRolesPanel
        databaseId={databaseId}
        searchValue={searchValue}
        createOpen={createOpen}
        onCreateOpenChange={setCreateOpen}
      />
    </div>
  )
}
