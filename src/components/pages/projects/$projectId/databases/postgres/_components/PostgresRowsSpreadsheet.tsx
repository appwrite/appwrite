import { cn } from '@/lib/utils'
import { getColumnIcon } from '@/lib/utils/column-icons'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import { isPostgresPrimaryKeyColumn } from '@/lib/postgres-sql'
import {
  buildPostgresRowIdentityFromRow,
  getPostgresRowKey,
  POSTGRES_ROW_CTID_COLUMN,
} from '@/lib/postgres-row-sql'
import type { PostgresRowIdentity } from '@/lib/postgres-row-sql'
import { Pagination } from '@/components/global/shared/Pagination'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { PostgresInlineTableCell } from './PostgresInlineTableCell'
import { usePostgresRowsEditSession } from './PostgresRowsEditSession'
import { usePostgresRowsColumnResize } from './usePostgresRowsColumnResize'
import {
  computePostgresRowsTableWidthPx,
  getPostgresRowsDataColumnColStyle,
  getPostgresRowsDataColumnHeaderStyle,
  POSTGRES_BODY_CELL_BORDER_CLASS,
  POSTGRES_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS,
  POSTGRES_HEADER_CELL_BORDER_CLASS,
  POSTGRES_ROWS_TABLE_EDGE_COL_PX,
  POSTGRES_STICKY_THEAD_CLASS,
} from './postgres-spreadsheet-chrome'
import {
  COLUMN_RESIZE_RAILS_LAYER_CLASS,
} from '@/lib/layout/horizontal-resize'
import {
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_START_EDGE_SHADOW,
  SPREADSHEET_STICKY_START_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  type MouseEvent,
} from 'react'
import { useT } from '@/lib/i18n/translate'

type PostgresRowsSpreadsheetProps = {
  databaseId: string
  tableId: string
  columns: PostgresTableColumnRow[]
  rows: Record<string, unknown>[]
  rowNumberOffset?: number
  canWrite?: boolean
  isLoading?: boolean
  emptyContent?: React.ReactNode
  onOpenRow?: (row: Record<string, unknown>, focusedField?: string) => void
  selectedRowKeys?: Set<string>
  onToggleRow?: (rowKey: string, identity: PostgresRowIdentity) => void
  onToggleAllRows?: (
    rows: Array<{ rowKey: string; identity: PostgresRowIdentity }>,
    selectAll: boolean,
  ) => void
  currentPage: number
  totalItems: number
  pageSize: number
  onPageChange: (page: number) => void
  onPageSizeChange: (pageSize: number) => void
  onPaginationInteract?: () => void
}

export function PostgresRowsSpreadsheet({
  databaseId,
  tableId,
  columns,
  rows,
  rowNumberOffset = 0,
  canWrite = true,
  isLoading = false,
  emptyContent,
  onOpenRow,
  selectedRowKeys,
  onToggleRow,
  onToggleAllRows,
  currentPage,
  totalItems,
  pageSize,
  onPageChange,
  onPageSizeChange,
  onPaginationInteract,
}: PostgresRowsSpreadsheetProps) {
  const t = useT()
  const editSession = usePostgresRowsEditSession()
  const scrollRef = useRef<HTMLDivElement | null>(null)
  const drawerOpenTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const selectionEnabled =
    canWrite && selectedRowKeys != null && onToggleRow != null

  const visibleColumns = useMemo(
    () =>
      columns.filter(
        (column) => column.column_name !== POSTGRES_ROW_CTID_COLUMN,
      ),
    [columns],
  )

  const rowEntries = useMemo(
    () =>
      rows.map((row) => ({
        row,
        rowKey: getPostgresRowKey(row, columns),
        identity: buildPostgresRowIdentityFromRow(row, columns),
      })),
    [columns, rows],
  )

  const selectedOnPageCount = useMemo(() => {
    if (!selectedRowKeys) return 0
    return rowEntries.filter((entry) => selectedRowKeys.has(entry.rowKey))
      .length
  }, [rowEntries, selectedRowKeys])

  const allPageSelected =
    selectionEnabled &&
    rowEntries.length > 0 &&
    selectedOnPageCount === rowEntries.length
  const somePageSelected =
    selectionEnabled && selectedOnPageCount > 0 && !allPageSelected

  const columnKeys = useMemo(
    () => visibleColumns.map((column) => column.column_name),
    [visibleColumns],
  )

  const {
    tableLayerRef,
    headerThRefs,
    colRefs,
    railRefs,
    getColumnWidthPx,
    resizingColumnKey,
    handleResizePointerDown,
  } = usePostgresRowsColumnResize(databaseId, tableId, columnKeys, scrollRef)

  const tableMinWidthPx = useMemo(
    () => computePostgresRowsTableWidthPx(columnKeys, getColumnWidthPx),
    [columnKeys, getColumnWidthPx],
  )

  const cancelDrawerOpen = useCallback(() => {
    if (drawerOpenTimerRef.current) {
      clearTimeout(drawerOpenTimerRef.current)
      drawerOpenTimerRef.current = null
    }
  }, [])

  const handleCellClick = useCallback(
    (row: Record<string, unknown>, columnName?: string) =>
      (event: MouseEvent) => {
        if (event.detail > 1) return
        cancelDrawerOpen()
        drawerOpenTimerRef.current = setTimeout(() => {
          if (editSession?.consumeSuppressNextDrawerOpen()) return
          onOpenRow?.(row, columnName)
        }, 200)
      },
    [cancelDrawerOpen, editSession, onOpenRow],
  )

  useEffect(() => {
    return () => {
      cancelDrawerOpen()
    }
  }, [cancelDrawerOpen])

  const handlePaginationInteract = useCallback(() => {
    cancelDrawerOpen()
    onPaginationInteract?.()
  }, [cancelDrawerOpen, onPaginationInteract])

  const showEmptyTable = !isLoading && (visibleColumns.length === 0 || rows.length === 0)

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div
        ref={scrollRef}
        className="min-h-0 flex-1 overflow-auto overscroll-contain"
      >
        {isLoading && rows.length === 0 ? (
          <div className="flex h-full min-h-[12rem] items-center justify-center text-[13px] text-muted-foreground">
            {t('Loading rows…')}
          </div>
        ) : showEmptyTable ? (
          <div className="flex h-full min-h-[12rem] items-center justify-center px-4">
            {emptyContent ?? (
              <p className="text-[13px] text-muted-foreground">
                {t('No rows to display.')}
              </p>
            )}
          </div>
        ) : (
          <div
            ref={tableLayerRef}
            className={SPREADSHEET_SCROLL_LAYER_CLASS}
            style={{ minWidth: tableMinWidthPx }}
          >
            <table className="relative z-0 w-full table-fixed border-collapse">
              <colgroup>
                <col
                  style={{
                    width: POSTGRES_ROWS_TABLE_EDGE_COL_PX,
                    minWidth: POSTGRES_ROWS_TABLE_EDGE_COL_PX,
                    maxWidth: POSTGRES_ROWS_TABLE_EDGE_COL_PX,
                  }}
                />
                {visibleColumns.map((column, columnIndex) => {
                  const key = column.column_name
                  const width = getColumnWidthPx(key)
                  const isDragResize = resizingColumnKey === key
                  return (
                    <col
                      key={key}
                      ref={(node) => {
                        if (node) colRefs.current.set(key, node)
                        else colRefs.current.delete(key)
                      }}
                      style={getPostgresRowsDataColumnColStyle(
                        columnIndex,
                        visibleColumns.length,
                        width,
                        isDragResize,
                      )}
                    />
                  )
                })}
              </colgroup>
              <thead className={POSTGRES_STICKY_THEAD_CLASS}>
                <tr>
                  <th
                    className={cn(
                      'sticky start-0 z-30 bg-background px-2 py-2 text-center text-[12px] font-semibold uppercase tracking-wider text-muted-foreground',
                      SPREADSHEET_STICKY_START_HEADER_SHADOW,
                    )}
                    style={{
                      width: POSTGRES_ROWS_TABLE_EDGE_COL_PX,
                      minWidth: POSTGRES_ROWS_TABLE_EDGE_COL_PX,
                      maxWidth: POSTGRES_ROWS_TABLE_EDGE_COL_PX,
                    }}
                  >
                    {selectionEnabled ? (
                      <div className="flex justify-center">
                        <Checkbox
                          checked={
                            allPageSelected
                              ? true
                              : somePageSelected
                                ? 'indeterminate'
                                : false
                          }
                          onCheckedChange={(checked) => {
                            onToggleAllRows?.(
                              rowEntries.map(({ rowKey, identity }) => ({
                                rowKey,
                                identity,
                              })),
                              checked === true,
                            )
                          }}
                          onClick={(event) => event.stopPropagation()}
                          aria-label={t('Select all rows')}
                        />
                      </div>
                    ) : (
                      '#'
                    )}
                  </th>
                  {visibleColumns.map((column, columnIndex) => {
                    const key = column.column_name
                    const ColumnIcon = getColumnIcon(
                      column.udt_name || column.data_type,
                    )
                    const isPrimaryKey = isPostgresPrimaryKeyColumn(column)
                    const isDragResize = resizingColumnKey === key
                    const width = getColumnWidthPx(key)
                    const isLastColumn =
                      columnIndex === visibleColumns.length - 1
                    return (
                      <th
                        key={key}
                        ref={(node) => {
                          if (node) headerThRefs.current.set(key, node)
                          else headerThRefs.current.delete(key)
                        }}
                        className={cn(
                          'px-3 py-2',
                          POSTGRES_HEADER_CELL_BORDER_CLASS,
                          isLastColumn && 'border-e-0',
                        )}
                        style={getPostgresRowsDataColumnHeaderStyle(
                          columnIndex,
                          visibleColumns.length,
                          width,
                          isDragResize,
                        )}
                      >
                        <div className="flex min-w-0 items-center gap-2 pe-1.5">
                          <ColumnIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                          <span className="min-w-0 truncate text-[12px] font-medium text-foreground">
                            {key}
                          </span>
                          {isPrimaryKey ? (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span className="inline-flex shrink-0 items-center rounded border border-border bg-muted/50 px-1 py-px text-[9px] font-semibold uppercase tracking-wider text-muted-foreground">
                                  PK
                                </span>
                              </TooltipTrigger>
                              <TooltipContent side="top">
                                {t('Primary key')}
                              </TooltipContent>
                            </Tooltip>
                          ) : null}
                        </div>
                      </th>
                    )
                  })}
                </tr>
              </thead>
              <tbody>
                {rowEntries.map(({ row, rowKey, identity }, rowIndex) => {
                  const isSelected = selectedRowKeys?.has(rowKey) ?? false
                  const hasPendingEdits =
                    editSession?.isRowEdited(tableId, rowKey) ?? false
                  return (
                    <tr
                      key={rowKey}
                      className={cn(
                        'group cursor-pointer transition-colors',
                        isSelected && !hasPendingEdits && 'bg-muted',
                        hasPendingEdits &&
                          'bg-amber-500/10 ring-1 ring-inset ring-amber-500/20 hover:bg-amber-500/15',
                        !hasPendingEdits && !isSelected && 'hover:bg-muted/50',
                        !hasPendingEdits &&
                          !isSelected &&
                          rowIndex % 2 === 1 &&
                          'bg-muted/15',
                      )}
                    >
                      <td
                        className={cn(
                          'sticky start-0 z-10 border-b border-border px-2 py-2.5 text-center',
                          isSelected || hasPendingEdits
                            ? 'bg-muted'
                            : 'bg-background',
                          hasPendingEdits && 'bg-amber-500/10',
                          SPREADSHEET_STICKY_START_EDGE_SHADOW,
                        )}
                        onClick={(event) => event.stopPropagation()}
                      >
                        {selectionEnabled ? (
                          <div className="flex justify-center">
                            <Checkbox
                              checked={isSelected}
                              onCheckedChange={() =>
                                onToggleRow?.(rowKey, identity)
                              }
                              onClick={(event) => event.stopPropagation()}
                              aria-label={t('Select row')}
                            />
                          </div>
                        ) : (
                          <span className="text-[11px] tabular-nums text-muted-foreground">
                            {rowNumberOffset + rowIndex + 1}
                          </span>
                        )}
                      </td>
                      {visibleColumns.map((column, columnIndex) => {
                        const rawValue = row[column.column_name]
                        const isLastColumn =
                          columnIndex === visibleColumns.length - 1
                        return (
                          <PostgresInlineTableCell
                            key={column.column_name}
                            tableId={tableId}
                            rowKey={rowKey}
                            column={column}
                            identity={identity}
                            originalValue={rawValue as never}
                            canWrite={canWrite}
                            className={
                              isLastColumn ? 'border-e-0' : undefined
                            }
                            onCancelDrawerOpen={cancelDrawerOpen}
                            onCellClick={handleCellClick(
                              row,
                              column.column_name,
                            )}
                          />
                        )
                      })}
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <div className={COLUMN_RESIZE_RAILS_LAYER_CLASS} aria-hidden>
              {visibleColumns.map((column) => {
                const key = column.column_name
                return (
                  <button
                    key={`col-resize-rail-${key}`}
                    ref={(node) => {
                      if (node) railRefs.current.set(key, node)
                      else railRefs.current.delete(key)
                    }}
                    type="button"
                    aria-label={`Resize ${key} column width`}
                    aria-orientation="vertical"
                    role="separator"
                    tabIndex={0}
                    onPointerDown={handleResizePointerDown(key)}
                    className={cn(
                      POSTGRES_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS,
                      resizingColumnKey === key && 'before:opacity-100',
                      'focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                    )}
                  />
                )
              })}
            </div>
          </div>
        )}
      </div>

      <div className="h-[54px] shrink-0 border-t border-border bg-background">
        <div
          className="@container flex h-full items-center px-4 sm:px-6"
          onPointerDownCapture={handlePaginationInteract}
        >
          <Pagination
            currentPage={currentPage}
            totalItems={totalItems}
            pageSize={pageSize}
            pageSizeOptions={[10, 25, 50, 100]}
            onPageChange={onPageChange}
            onPageSizeChange={onPageSizeChange}
            itemLabel="rows"
            className="h-full min-h-0 w-full border-0 mt-0 py-0"
          />
        </div>
      </div>
    </div>
  )
}
