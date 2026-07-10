import {
  useExecutePostgresSql,
  useExplainPostgresSql,
  usePostgresTableRows,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  normalizePostgresTableRouteId,
  parsePostgresTableId,
  postgresNav,
} from '@/lib/postgres-database-routes'
import { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { canSaveTeamFilters } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useDatabaseOperationsLock } from '../_components/DatabaseOperationsLockContext'
import { ReadOnlyDataSpreadsheet } from '@/components/global/shared/ReadOnlyDataSpreadsheet'
import { PostgresQueryResultsMeta } from './_components/PostgresQueryResultsMeta'
import { Pagination } from '@/components/global/shared/Pagination'
import { SqlWorkbench } from './SqlWorkbench'
import { PostgresTableRowsEmptyState } from './_components/PostgresTableRowsEmptyState'
import { SqlWorkbenchPanelEmptyState } from './_components/SqlWorkbenchPanelEmptyState'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'
import { useT } from '@/lib/i18n/translate'

export type PostgresSqlWorkbenchProps = {
  databaseId: string
  /** Table id from the `/tables/$tableId/rows` URL, when present. */
  routeTableId?: string
}

export function PostgresSqlWorkbench({
  databaseId,
  routeTableId,
}: PostgresSqlWorkbenchProps) {
  return (
    <PostgresSqlWorkbenchContent
      databaseId={databaseId}
      routeTableId={routeTableId}
    />
  )
}

/** @deprecated Use {@link PostgresSqlWorkbench} from the database layout. */
export function Workspace({
  databaseId,
  tableId,
}: {
  databaseId: string
  tableId: string
}) {
  return (
    <PostgresSqlWorkbench databaseId={databaseId} routeTableId={tableId} />
  )
}

type PostgresSqlWorkbenchContentProps = {
  databaseId: string
  routeTableId?: string
}

export function PostgresSqlWorkbenchContent({
  databaseId,
  routeTableId,
}: PostgresSqlWorkbenchContentProps) {
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
  } = usePostgresSidebar()

  useLayoutEffect(() => {
    if (!routeTableId) return
    focusTableRouteTab(routeTableId)
  }, [focusTableRouteTab, routeTableId])

  const normalizedRouteTableId = routeTableId
    ? normalizePostgresTableRouteId(routeTableId)
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
    ? parsePostgresTableId(activeTableId)
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
  } = usePostgresTableRows(
    projectId,
    databaseId,
    activeTableId ?? null,
    page - 1,
    pageSize,
  )

  const executeSql = useExecutePostgresSql(projectId, databaseId)
  const explainSql = useExplainPostgresSql(projectId, databaseId)

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
          ...postgresNav({ projectId, databaseId }).sql(),
          replace: true,
        })
        return
      }

      navigate({
        ...postgresNav({ projectId, databaseId }).sql(),
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
      emptyContent={<PostgresTableRowsEmptyState />}
      header={
        <PostgresQueryResultsMeta
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
