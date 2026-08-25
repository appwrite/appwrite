import {
  useExecuteMysqlSql,
  useExplainMysqlSql,
  useMysqlTableRows,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  normalizeMysqlTableRouteId,
  parseMysqlTableId,
  mysqlNav,
} from '@/lib/mysql-database-routes'
import { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useDatabaseOperationsLock } from '../_components/DatabaseOperationsLockContext'
import { ReadOnlyDataSpreadsheet } from '@/components/global/shared/ReadOnlyDataSpreadsheet'
import { MysqlQueryResultsMeta } from './_components/MysqlQueryResultsMeta'
import { Pagination } from '@/components/global/shared/Pagination'
import { SqlWorkbench } from './SqlWorkbench'
import { MysqlTableRowsEmptyState } from './_components/MysqlTableRowsEmptyState'
import { SqlWorkbenchPanelEmptyState } from './_components/SqlWorkbenchPanelEmptyState'
import { useMysqlSidebar } from './_components/MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

export type MysqlSqlWorkbenchProps = {
  databaseId: string
  /** Table id from the `/tables/$tableId/rows` URL, when present. */
  routeTableId?: string
}

export function MysqlSqlWorkbench({
  databaseId,
  routeTableId,
}: MysqlSqlWorkbenchProps) {
  return (
    <MysqlSqlWorkbenchContent
      databaseId={databaseId}
      routeTableId={routeTableId}
    />
  )
}

/** @deprecated Use {@link MysqlSqlWorkbench} from the database layout. */
export function Workspace({
  databaseId,
  tableId,
}: {
  databaseId: string
  tableId: string
}) {
  return (
    <MysqlSqlWorkbench databaseId={databaseId} routeTableId={tableId} />
  )
}

type MysqlSqlWorkbenchContentProps = {
  databaseId: string
  routeTableId?: string
}

export function MysqlSqlWorkbenchContent({
  databaseId,
  routeTableId,
}: MysqlSqlWorkbenchContentProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? null
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(teamId ?? undefined)
  const canSaveTeam = canSaveTeamFilters(access, features)
  const { isOperationsLocked, operationsLockTooltip } =
    useDatabaseOperationsLock()

  const {
    tabs,
    activeTabId,
    activeTab,
    setActiveTabId,
    updateActiveTabSql,
    setActiveTabResult,
    createTab,
    closeTab,
    reorderTabs,
    renameTab,
    addRecentQuery,
    focusTableRouteTab,
  } = useMysqlSidebar()

  useLayoutEffect(() => {
    if (!routeTableId) return
    focusTableRouteTab(routeTableId)
  }, [focusTableRouteTab, routeTableId])

  const normalizedRouteTableId = routeTableId
    ? normalizeMysqlTableRouteId(routeTableId)
    : undefined

  const routeTableTab = useMemo(
    () =>
      normalizedRouteTableId
        ? tabs.find((tab) => tab.tableId === normalizedRouteTableId)
        : undefined,
    [normalizedRouteTableId, tabs],
  )

  const editorActiveTabId = routeTableTab?.id ?? activeTabId
  const editorActiveTab =
    tabs.find((tab) => tab.id === editorActiveTabId) ?? activeTab

  const activeTableId =
    normalizedRouteTableId ?? editorActiveTab.tableId ?? null
  const selectedTable = activeTableId
    ? parseMysqlTableId(activeTableId)
    : undefined

  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(ROWS_DEFAULT_PAGE_SIZE)

  useEffect(() => {
    setPage(1)
    setPageSize(ROWS_DEFAULT_PAGE_SIZE)
  }, [activeTableId])

  const {
    rows,
    total,
    columns,
    durationMs,
    truncated,
    isLoading: rowsLoading,
    isFetching: rowsFetching,
  } = useMysqlTableRows(
    projectId,
    databaseId,
    activeTableId ?? null,
    page - 1,
    pageSize,
  )

  const executeSql = useExecuteMysqlSql(projectId, databaseId)
  const explainSql = useExplainMysqlSql(projectId, databaseId)

  const tableColumns =
    columns.length > 0
      ? columns.map((column) => ({ key: column.name, label: column.name }))
      : rows[0]
        ? Object.keys(rows[0]).map((key) => ({ key, label: key }))
        : []

  const handleRunSql = useCallback(async () => {
    const trimmed = editorActiveTab.sql.trim()
    if (!trimmed) return

    setActiveTabResult(null, null, 'query')

    try {
      const result = await executeSql.mutateAsync(trimmed)
      setActiveTabResult(result, null, 'query')
      addRecentQuery(trimmed)
    } catch (error) {
      setActiveTabResult(null, error, 'query')
    }
  }, [
    addRecentQuery,
    editorActiveTab.sql,
    executeSql,
    setActiveTabResult,
  ])

  const handleExplainSql = useCallback(async () => {
    const trimmed = editorActiveTab.sql.trim()
    if (!trimmed) return

    setActiveTabResult(null, null, 'explain', null)

    try {
      const explanation = await explainSql.mutateAsync(trimmed)
      setActiveTabResult(null, null, 'explain', explanation)
    } catch (error) {
      setActiveTabResult(null, error, 'explain', null)
    }
  }, [
    editorActiveTab.sql,
    explainSql,
    setActiveTabResult,
  ])

  const handleSelectTab = useCallback(
    (tabId: string) => {
      setActiveTabId(tabId)
      const tab = tabs.find((entry) => entry.id === tabId)
      if (!tab) return

      if (!tab.tableId) {
        navigate({
          ...mysqlNav({ projectId, databaseId }).sql(),
          replace: true,
        })
        return
      }

      navigate({
        ...mysqlNav({ projectId, databaseId }).sql(),
        replace: true,
      })
    },
    [databaseId, navigate, projectId, setActiveTabId, tabs],
  )

  const tableRowsPanel = selectedTable ? (
    <ReadOnlyDataSpreadsheet
      variant="studio"
      showRowNumbers
      enableColumnResize
      rowNumberOffset={(page - 1) * pageSize}
      columns={tableColumns}
      rows={rows}
      getRowKey={(row, index) =>
        typeof row.$id === 'string' ? row.$id : `table-row-${index}`
      }
      isLoading={rowsLoading || rowsFetching}
      loadingLabel={t('Loading rows…')}
      emptyContent={<MysqlTableRowsEmptyState />}
      header={
        <MysqlQueryResultsMeta
          title={`${selectedTable.schema}.${selectedTable.table}`}
          rowCount={total}
          durationMs={durationMs}
          truncated={truncated}
        />
      }
      footer={
        total > pageSize ? (
          <Pagination
            currentPage={page}
            totalItems={total}
            pageSize={pageSize}
            pageSizeOptions={[10, 25, 50, 100]}
            onPageChange={setPage}
            onPageSizeChange={(nextPageSize) => {
              setPageSize(nextPageSize)
              setPage(1)
            }}
            itemLabel="rows"
            className="h-full min-h-0 border-0 mt-0 py-0"
          />
        ) : undefined
      }
    />
  ) : (
    <SqlWorkbenchPanelEmptyState variant="results" />
  )

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <SqlWorkbench
        projectId={projectId}
        databaseId={databaseId}
        tabs={tabs}
        activeTabId={editorActiveTabId}
        sql={editorActiveTab.sql}
        onSqlChange={updateActiveTabSql}
        onSelectTab={handleSelectTab}
        onCreateTab={createTab}
        onCloseTab={closeTab}
        onReorderTabs={reorderTabs}
        onRenameTab={renameTab}
        onRun={handleRunSql}
        onExplain={handleExplainSql}
        isRunning={executeSql.isPending}
        isExplaining={explainSql.isPending}
        error={editorActiveTab.error ?? executeSql.error ?? explainSql.error}
        result={editorActiveTab.result}
        explainResult={editorActiveTab.explainResult}
        resultKind={editorActiveTab.resultKind}
        account={accountPrefs}
        teamId={teamId}
        canSaveTeam={canSaveTeam}
        canRun={!isOperationsLocked}
        runDisabledTooltip={operationsLockTooltip}
        canExplain={!isOperationsLocked}
        explainDisabledTooltip={operationsLockTooltip}
      >
        {tableRowsPanel}
      </SqlWorkbench>
    </div>
  )
}
