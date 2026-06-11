import {
  useExecutePostgresSql,
  usePostgresTableRows,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { parsePostgresTableId } from '@/lib/postgres-database-routes'
import { buildPostgresSelectSql } from '@/lib/postgres-sql'
import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Badge } from '@/components/ui/badge'
import type { Models } from '@appwrite.io/console'
import { ReadOnlyDataSpreadsheet } from '@/components/global/shared/ReadOnlyDataSpreadsheet'
import { Pagination } from '@/components/global/shared/Pagination'
import { SqlWorkbench } from './SqlWorkbench'
import { PostgresShell } from './PostgresShell'
import { PostgresConnectionDetails } from './PostgresConnectionDetails'
import { PostgresTableRowsEmptyState } from './_components/PostgresTableRowsEmptyState'
import { usePostgresSidebar } from './_components/PostgresSidebarContext'

export type PostgresWorkspaceProps = {
  databaseId: string
  tableId: string
}

const DEFAULT_SQL = 'SELECT NOW() AS current_time;'

function sqlForTable(tableId: string, limit = ROWS_DEFAULT_PAGE_SIZE): string {
  if (tableId === '-') return DEFAULT_SQL
  const { schema, table } = parsePostgresTableId(tableId)
  return buildPostgresSelectSql(schema, table, limit, 0)
}

export function Workspace({ databaseId, tableId }: PostgresWorkspaceProps) {
  const isDatabaseLevelView = tableId === '-'

  return (
    <PostgresShell
      databaseId={databaseId}
      tableId={isDatabaseLevelView ? undefined : tableId}
    >
      <WorkspaceContent databaseId={databaseId} tableId={tableId} />
    </PostgresShell>
  )
}

function WorkspaceContent({ databaseId, tableId }: PostgresWorkspaceProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const isDatabaseLevelView = tableId === '-'
  const selectedTable = isDatabaseLevelView ? undefined : parsePostgresTableId(tableId)

  const [sql, setSql] = useState(() => sqlForTable(tableId))
  const [manualResult, setManualResult] =
    useState<Models.DedicatedDatabaseExecution | null>(null)
  const [manualError, setManualError] = useState<unknown>(null)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(ROWS_DEFAULT_PAGE_SIZE)

  useEffect(() => {
    setSql(sqlForTable(tableId))
    setManualResult(null)
    setManualError(null)
    setPage(1)
    setPageSize(ROWS_DEFAULT_PAGE_SIZE)
  }, [tableId])

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
    isDatabaseLevelView ? null : tableId,
    page - 1,
    pageSize,
  )

  const executeSql = useExecutePostgresSql(projectId, databaseId)
  const { registerSqlLoader, addRecentQuery } = usePostgresSidebar()

  useEffect(() => {
    registerSqlLoader((nextSql) => {
      setSql(nextSql)
      setManualResult(null)
      setManualError(null)
    })
  }, [registerSqlLoader])

  const tableColumns =
    columns.length > 0
      ? columns.map((column) => ({ key: column.name, label: column.name }))
      : rows[0]
        ? Object.keys(rows[0]).map((key) => ({ key, label: key }))
        : []

  const handleRunSql = async () => {
    const trimmed = sql.trim()
    if (!trimmed) return
    setManualError(null)
    try {
      const result = await executeSql.mutateAsync(trimmed)
      setManualResult(result)
      addRecentQuery(trimmed)
    } catch (error) {
      setManualResult(null)
      setManualError(error)
    }
  }

  const tableRowsPanel = !isDatabaseLevelView && selectedTable ? (
    <ReadOnlyDataSpreadsheet
      columns={tableColumns}
      rows={rows}
      getRowKey={(row, index) =>
        typeof row.$id === 'string' ? row.$id : `table-row-${index}`
      }
      isLoading={rowsLoading || rowsFetching}
      loadingLabel="Loading rows…"
      emptyContent={
        <PostgresTableRowsEmptyState
          projectId={projectId}
          databaseId={databaseId}
        />
      }
      header={
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[13px] font-semibold text-foreground">
            {selectedTable.schema}.{selectedTable.table}
          </span>
          {typeof durationMs === 'number' ? (
            <Badge variant="info" className="text-[10px] shrink-0">
              {durationMs} ms
            </Badge>
          ) : null}
          {truncated ? (
            <Badge variant="warning" className="text-[10px] shrink-0">
              Truncated
            </Badge>
          ) : null}
        </div>
      }
      footer={
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
      }
    />
  ) : (
    <PostgresConnectionDetails
      projectId={projectId}
      databaseId={databaseId}
      centerInPanel
    />
  )

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <SqlWorkbench
        projectId={projectId}
        databaseId={databaseId}
        sql={sql}
        onSqlChange={setSql}
        onRun={() => void handleRunSql()}
        isRunning={executeSql.isPending}
        error={manualError ?? executeSql.error}
        result={manualResult}
      >
        {tableRowsPanel}
      </SqlWorkbench>
    </div>
  )
}
