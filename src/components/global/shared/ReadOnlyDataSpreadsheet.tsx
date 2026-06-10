import { cn } from '@/lib/utils'
import {
  formatSpreadsheetCellValue,
  isSpreadsheetRtlText,
} from '@/lib/spreadsheet-cell-formatting'
import type { ReactNode } from 'react'

/** Matches database product spreadsheet table chrome (see tablesdb/Spreadsheet.tsx). */
const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-r border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-r border-border'

export type ReadOnlyDataSpreadsheetColumn =
  | string
  | {
      key: string
      label?: string
    }

export type ReadOnlyDataSpreadsheetProps = {
  columns: ReadOnlyDataSpreadsheetColumn[]
  rows: Record<string, unknown>[]
  getRowKey?: (row: Record<string, unknown>, index: number) => string
  isLoading?: boolean
  loadingLabel?: string
  emptyLabel?: string
  className?: string
  header?: ReactNode
  footer?: ReactNode
  minColumnWidthPx?: number
}

function normalizeColumns(
  columns: ReadOnlyDataSpreadsheetColumn[],
): { key: string; label: string }[] {
  return columns.map((column) =>
    typeof column === 'string'
      ? { key: column, label: column }
      : { key: column.key, label: column.label ?? column.key },
  )
}

export function ReadOnlyDataSpreadsheet({
  columns,
  rows,
  getRowKey,
  isLoading = false,
  loadingLabel = 'Loading rows…',
  emptyLabel = 'No rows to display.',
  className,
  header,
  footer,
  minColumnWidthPx = 150,
}: ReadOnlyDataSpreadsheetProps) {
  const normalizedColumns = normalizeColumns(columns)

  if (isLoading && rows.length === 0) {
    return (
      <div
        className={cn(
          'flex h-full min-h-[12rem] items-center justify-center text-[13px] text-muted-foreground',
          className,
        )}
      >
        {loadingLabel}
      </div>
    )
  }

  return (
    <div className={cn('flex min-h-0 flex-1 flex-col overflow-hidden', className)}>
      {header ? (
        <div className="shrink-0 border-b border-border bg-background px-4 py-2 sm:px-6">
          {header}
        </div>
      ) : null}

      <div className="min-h-0 flex-1 overflow-auto">
        {normalizedColumns.length === 0 || rows.length === 0 ? (
          <div className="flex h-full min-h-[12rem] items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
            {emptyLabel}
          </div>
        ) : (
          <table className="w-full min-w-max border-collapse text-left">
            <colgroup>
              {normalizedColumns.map((column) => (
                <col
                  key={column.key}
                  style={{ minWidth: `${minColumnWidthPx}px` }}
                />
              ))}
            </colgroup>
            <thead className={stickyTheadClass}>
              <tr>
                {normalizedColumns.map((column) => (
                  <th
                    key={column.key}
                    className={cn(
                      'px-3 py-2 text-left text-[12px] font-medium text-foreground',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="block truncate">{column.label}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, rowIndex) => {
                const rowKey = getRowKey?.(row, rowIndex) ?? String(rowIndex)
                return (
                  <tr
                    key={rowKey}
                    className="transition-colors hover:bg-muted/50"
                  >
                    {normalizedColumns.map((column) => {
                      const { full, display, isNull } = formatSpreadsheetCellValue(
                        row[column.key],
                      )
                      const isRtl = isSpreadsheetRtlText(full)
                      return (
                        <td
                          key={column.key}
                          className={cn('px-3 py-1.5', bodyCellBorderClass)}
                        >
                          <span
                            className={cn(
                              'block max-w-[320px] truncate whitespace-nowrap text-[12px] font-mono',
                              isNull ? 'text-foreground/60' : 'text-foreground',
                            )}
                            title={full}
                            dir={isRtl ? 'rtl' : 'ltr'}
                          >
                            {display}
                          </span>
                        </td>
                      )
                    })}
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {footer ? (
        <div className="shrink-0 border-t border-border bg-background px-4 py-3 sm:px-6">
          {footer}
        </div>
      ) : null}
    </div>
  )
}
