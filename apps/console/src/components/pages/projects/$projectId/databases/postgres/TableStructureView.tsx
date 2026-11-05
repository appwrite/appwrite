import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import {
  usePostgresTableColumns,
  usePostgresTableIndexes,
} from '@/lib/react-query/hooks'
import type { PostgresTableTab } from '@/lib/postgres-database-routes'
import { usePostgresTableHeaderSlot } from './_components/PostgresTableHeaderSlotContext'
import { PostgresTableColumnsPanel } from './_components/PostgresTableColumnsPanel'
import { PostgresTableIndexesPanel } from './_components/PostgresTableIndexesPanel'
import { PostgresTablePropertiesPanel } from './_components/PostgresTablePropertiesPanel'
import { useT } from '@/lib/i18n/translate'
export type TableStructureViewProps = {
  databaseId: string
  tableId: string
  activeTab: Exclude<PostgresTableTab, 'rows'>
}

export function TableStructureView({
  databaseId,
  tableId,
  activeTab,
}: TableStructureViewProps) {
  return (
    <TableStructureContent
      databaseId={databaseId}
      tableId={tableId}
      activeTab={activeTab}
    />
  )
}

export function TableStructureContent({
  databaseId,
  tableId,
  activeTab,
}: TableStructureViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { canWrite, writeTooltip } = useDatabaseTableOperationsAccess()

  const {
    refetch: refetchColumns,
    isFetching: columnsFetching,
  } = usePostgresTableColumns(projectId, databaseId, tableId)
  const {
    refetch: refetchIndexes,
    isFetching: indexesFetching,
  } = usePostgresTableIndexes(projectId, databaseId, tableId)

  const [columnCreateOpen, setColumnCreateOpen] = useState(false)
  const [indexCreateOpen, setIndexCreateOpen] = useState(false)
  const [searchValue, setSearchValue] = useState('')

  useEffect(() => {
    setSearchValue('')
  }, [activeTab, tableId])

  const headerProps = useMemo(() => {
    const searchProps =
      activeTab === 'columns' || activeTab === 'indexes'
        ? {
            searchPlaceholder:
              activeTab === 'columns'
                ? t('Search columns...')
                : t('Search indexes...'),
            searchValue,
            onSearchChange: setSearchValue,
          }
        : {}

    if (activeTab === 'columns') {
      return {
        ...searchProps,
        createLabel: canWrite ? t('Add column') : undefined,
        onCreate: canWrite ? () => setColumnCreateOpen(true) : undefined,
        createDisabled: !canWrite,
        createDisabledTooltip: writeTooltip,
        showRefresh: true,
        onRefresh: () => void refetchColumns(),
        isRefreshing: columnsFetching,
      }
    }
    if (activeTab === 'indexes') {
      return {
        ...searchProps,
        createLabel: canWrite ? t('Create index') : undefined,
        onCreate: canWrite ? () => setIndexCreateOpen(true) : undefined,
        createDisabled: !canWrite,
        createDisabledTooltip: writeTooltip,
        showRefresh: true,
        onRefresh: () => void refetchIndexes(),
        isRefreshing: indexesFetching,
      }
    }
    return {}
  }, [
    activeTab,
    canWrite,
    writeTooltip,
    columnsFetching,
    indexesFetching,
    refetchColumns,
    refetchIndexes,
    searchValue,
    t,
  ])

  usePostgresTableHeaderSlot(headerProps)

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      {activeTab === 'columns' ? (
          <PostgresTableColumnsPanel
            databaseId={databaseId}
            tableId={tableId}
            search={searchValue}
            createDialogOpen={columnCreateOpen}
            onCreateDialogOpenChange={setColumnCreateOpen}
          />
        ) : null}
        {activeTab === 'indexes' ? (
          <PostgresTableIndexesPanel
            databaseId={databaseId}
            tableId={tableId}
            search={searchValue}
            createDialogOpen={indexCreateOpen}
            onCreateDialogOpenChange={setIndexCreateOpen}
          />
        ) : null}
        {activeTab === 'settings' ? (
          <PostgresTablePropertiesPanel
            databaseId={databaseId}
            tableId={tableId}
          />
        ) : null}
    </div>
  )
}
