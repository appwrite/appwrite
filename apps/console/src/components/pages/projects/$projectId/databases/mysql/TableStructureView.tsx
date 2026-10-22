import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import {
  useMysqlTableColumns,
  useMysqlTableIndexes,
} from '@/lib/react-query/hooks'
import type { MysqlTableTab } from '@/lib/mysql-database-routes'
import { useMysqlTableHeaderSlot } from './_components/MysqlTableHeaderSlotContext'
import { MysqlTableColumnsPanel } from './_components/MysqlTableColumnsPanel'
import { MysqlTableIndexesPanel } from './_components/MysqlTableIndexesPanel'
import { MysqlTablePropertiesPanel } from './_components/MysqlTablePropertiesPanel'
import { useT } from '@/lib/i18n/translate'
export type TableStructureViewProps = {
  databaseId: string
  tableId: string
  activeTab: Exclude<MysqlTableTab, 'rows'>
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
  } = useMysqlTableColumns(projectId, databaseId, tableId)
  const {
    refetch: refetchIndexes,
    isFetching: indexesFetching,
  } = useMysqlTableIndexes(projectId, databaseId, tableId)

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

  useMysqlTableHeaderSlot(headerProps)

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      {activeTab === 'columns' ? (
          <MysqlTableColumnsPanel
            databaseId={databaseId}
            tableId={tableId}
            search={searchValue}
            createDialogOpen={columnCreateOpen}
            onCreateDialogOpenChange={setColumnCreateOpen}
          />
        ) : null}
        {activeTab === 'indexes' ? (
          <MysqlTableIndexesPanel
            databaseId={databaseId}
            tableId={tableId}
            search={searchValue}
            createDialogOpen={indexCreateOpen}
            onCreateDialogOpenChange={setIndexCreateOpen}
          />
        ) : null}
        {activeTab === 'settings' ? (
          <MysqlTablePropertiesPanel
            databaseId={databaseId}
            tableId={tableId}
          />
        ) : null}
    </div>
  )
}
