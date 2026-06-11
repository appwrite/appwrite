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
  /** Custom empty state content; takes precedence over `emptyLabel`. */
  emptyContent?: ReactNode
  className?: string
  header?: ReactNode
  footer?: ReactNode
  minColumnWidthPx?: number
  /** SQL studio styling: uppercase column headers, row numbers, taller cells. */
  variant?: 'default' | 'studio'
  showRowNumbers?: boolean
  /** Starting index for the row number column (e.g. pagination offset). */
  rowNumberOffset?: number
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
  emptyContent,
  className,
  header,
  footer,
  minColumnWidthPx = 150,
  variant = 'default',
  showRowNumbers = false,
  rowNumberOffset = 0,
}: ReadOnlyDataSpreadsheetProps) {
  const normalizedColumns = normalizeColumns(columns)
  const isStudio = variant === 'studio'

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
        <div
          className={cn(
            'shrink-0 border-b border-border bg-background px-4 sm:px-6',
            isStudio ? 'py-3' : 'py-2',
          )}
        >
          {header}
        </div>
      ) : null}

      <div className="min-h-0 flex-1 overflow-auto">
        {normalizedColumns.length === 0 || rows.length === 0 ? (
          emptyContent ? (
            <div className="flex h-full min-h-[12rem] items-center justify-center px-4">
              {emptyContent}
            </div>
          ) : (
            <div className="flex h-full min-h-[12rem] items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
              {emptyLabel}
            </div>
          )
        ) : (
          <table className="w-full min-w-max border-collapse text-left">
            <colgroup>
              {showRowNumbers ? (
                <col style={{ width: '3rem' }} />
              ) : null}
              {normalizedColumns.map((column) => (
                <col
                  key={column.key}
                  style={{ minWidth: `${minColumnWidthPx}px` }}
                />
              ))}
            </colgroup>
            <thead className={stickyTheadClass}>
              <tr>
                {showRowNumbers ? (
                  <th
                    className={cn(
                      'w-12 px-3 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground',
                      headerCellBorderClass,
                    )}
                  >
                    #
                  </th>
                ) : null}
                {normalizedColumns.map((column) => (
                  <th
                    key={column.key}
                    className={cn(
                      isStudio
                        ? 'px-4 py-3 text-left text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                        : 'px-3 py-2 text-left text-[12px] font-medium text-foreground',
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
                    className={cn(
                      'transition-colors hover:bg-muted/50',
                      isStudio && rowIndex % 2 === 1 && 'bg-muted/15',
                    )}
                  >
                    {showRowNumbers ? (
                      <td
                        className={cn(
                          isStudio ? 'px-3 py-2.5' : 'px-3 py-1.5',
                          bodyCellBorderClass,
                        )}
                      >
                        <span className="block text-right text-[11px] tabular-nums text-muted-foreground">
                          {rowNumberOffset + rowIndex + 1}
                        </span>
                      </td>
                    ) : null}
                    {normalizedColumns.map((column) => {
                      const { full, display, isNull } = formatSpreadsheetCellValue(
                        row[column.key],
                      )
                      const isRtl = isSpreadsheetRtlText(full)
                      return (
                        <td
                          key={column.key}
                          className={cn(
                            isStudio ? 'px-4 py-2.5' : 'px-3 py-1.5',
                            bodyCellBorderClass,
                          )}
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
        <div className="h-[54px] shrink-0 border-t border-border bg-background">
          <div className="@container flex h-full items-center px-4 sm:px-6">
            <div className="min-w-0 flex-1">{footer}</div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
