import { cn } from '@/lib/utils'
import {
  formatSpreadsheetCellValue,
  isSpreadsheetRtlText,
} from '@/lib/spreadsheet-cell-formatting'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import {
  buildPostgresRowIdentityFromRow,
  getPostgresRowKey,
  POSTGRES_ROW_CTID_COLUMN,
} from '@/lib/postgres-row-sql'
import { PostgresInlineTableCell } from './PostgresInlineTableCell'
import { usePostgresRowsEditSession } from './PostgresRowsEditSession'
import { useCallback, useRef, type MouseEvent } from 'react'

const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-r border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-r border-border'

type PostgresRowsSpreadsheetProps = {
  tableId: string
  columns: PostgresTableColumnRow[]
  rows: Record<string, unknown>[]
  rowNumberOffset?: number
  canWrite?: boolean
  isLoading?: boolean
  emptyContent?: React.ReactNode
  onOpenRow?: (row: Record<string, unknown>, focusedField?: string) => void
}

export function PostgresRowsSpreadsheet({
  tableId,
  columns,
  rows,
  rowNumberOffset = 0,
  canWrite = true,
  isLoading = false,
  emptyContent,
  onOpenRow,
}: PostgresRowsSpreadsheetProps) {
  const editSession = usePostgresRowsEditSession()
  const drawerOpenTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const visibleColumns = columns.filter(
    (column) => column.column_name !== POSTGRES_ROW_CTID_COLUMN,
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

  if (isLoading && rows.length === 0) {
    return (
      <div className="flex h-full min-h-[12rem] items-center justify-center text-[13px] text-muted-foreground">
        Loading rows…
      </div>
    )
  }

  if (visibleColumns.length === 0 || rows.length === 0) {
    return (
      <div className="flex h-full min-h-[12rem] items-center justify-center px-4">
        {emptyContent ?? (
          <p className="text-[13px] text-muted-foreground">No rows to display.</p>
        )}
      </div>
    )
  }

  return (
    <div className="min-h-0 flex-1 overflow-auto">
      <table className="w-full min-w-max border-collapse text-left">
        <colgroup>
          <col style={{ width: '3rem' }} />
          {visibleColumns.map((column) => (
            <col key={column.column_name} style={{ minWidth: '150px' }} />
          ))}
        </colgroup>
        <thead className={stickyTheadClass}>
          <tr>
            <th
              className={cn(
                'w-12 px-3 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground',
                headerCellBorderClass,
              )}
            >
              #
            </th>
            {visibleColumns.map((column) => (
              <th
                key={column.column_name}
                className={cn(
                  'px-4 py-3 text-left text-[12px] font-semibold uppercase tracking-wider text-muted-foreground',
                  headerCellBorderClass,
                )}
              >
                <span className="block truncate">{column.column_name}</span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, rowIndex) => {
            const rowKey = getPostgresRowKey(row, columns)
            const identity = buildPostgresRowIdentityFromRow(row, columns)
            return (
              <tr
                key={rowKey}
                className={cn(
                  'transition-colors hover:bg-muted/50',
                  rowIndex % 2 === 1 && 'bg-muted/15',
                )}
              >
                <td className={cn('px-3 py-2.5', bodyCellBorderClass)}>
                  <span className="block text-right text-[11px] tabular-nums text-muted-foreground">
                    {rowNumberOffset + rowIndex + 1}
                  </span>
                </td>
                {visibleColumns.map((column) => {
                  const rawValue = row[column.column_name]
                  const { full, display, isNull } = formatSpreadsheetCellValue(
                    rawValue,
                  )
                  const isRtl = isSpreadsheetRtlText(full)
                  return (
                    <PostgresInlineTableCell
                      key={column.column_name}
                      tableId={tableId}
                      rowKey={rowKey}
                      column={column}
                      identity={identity}
                      originalValue={rawValue as never}
                      canWrite={canWrite}
                      display={display}
                      isNull={isNull}
                      title={full}
                      dir={isRtl ? 'rtl' : undefined}
                      onCancelDrawerOpen={cancelDrawerOpen}
                      onCellClick={handleCellClick(row, column.column_name)}
                    />
                  )
                })}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
