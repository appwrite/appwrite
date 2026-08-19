import { cn } from '@/lib/utils'
import {
  applyColumnResizeRailPosition,
  COLUMN_RESIZE_RAILS_LAYER_CLASS,
  columnResizeRailHandleClass,
  horizontalResizeDeltaPx,
  setBodyResizeDragActive,
} from '@/lib/layout/horizontal-resize'
import {
  SPREADSHEET_SCROLL_LAYER_CLASS,
} from '@/lib/layout/spreadsheet-sticky'
import {
  formatSpreadsheetCellValue,
  isSpreadsheetRtlText,
} from '@/lib/spreadsheet-cell-formatting'
import {
  cloneElement,
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from 'react'
import {
  SpreadsheetCellContextMenu,
  type SpreadsheetCellValueDialogState,
} from '@/components/global/shared/SpreadsheetCellContextMenu'
import { SpreadsheetCellValueDialog } from '@/components/global/shared/SpreadsheetCellValueDialog'
import { useT } from '@/lib/i18n/translate'

/** Matches database product spreadsheet table chrome (see tablesdb/Spreadsheet.tsx). */
const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-e border-border'
const rowNumberColumnWidthPx = 48
const resizableColumnDefaultWidthPx = 150
const resizableColumnMinWidthPx = 72
const resizableColumnMaxWidthPx = 640
const dataColumnResizeRailHandleClass = columnResizeRailHandleClass()

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
  /** Right-click menu on data cells (copy, view full value). Defaults to studio variant. */
  enableCellContextMenu?: boolean
  /** Show draggable column resize rails. Defaults to false. */
  enableColumnResize?: boolean
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

function clampColumnWidth(width: number): number {
  return Math.min(
    resizableColumnMaxWidthPx,
    Math.max(resizableColumnMinWidthPx, width),
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
  enableCellContextMenu,
  enableColumnResize = false,
}: ReadOnlyDataSpreadsheetProps) {
  const t = useT()
  const normalizedColumns = useMemo(() => normalizeColumns(columns), [columns])
  const isStudio = variant === 'studio'
  const showCellContextMenu = enableCellContextMenu ?? isStudio
  const [cellValueDialog, setCellValueDialog] =
    useState<SpreadsheetCellValueDialogState | null>(null)
  const scrollRef = useRef<HTMLDivElement | null>(null)
  const tableLayerRef = useRef<HTMLDivElement | null>(null)
  const headerThRefs = useRef<Map<string, HTMLTableCellElement>>(new Map())
  const colRefs = useRef<Map<string, HTMLTableColElement>>(new Map())
  const railRefs = useRef<Map<string, HTMLButtonElement>>(new Map())
  const columnKeys = useMemo(
    () => normalizedColumns.map((column) => column.key),
    [normalizedColumns],
  )
  const columnKeysRef = useRef(columnKeys)
  columnKeysRef.current = columnKeys
  const [columnWidths, setColumnWidths] = useState<Record<string, number>>({})
  const [resizingColumnKey, setResizingColumnKey] = useState<string | null>(null)
  const columnWidthsRef = useRef<Record<string, number>>({})

  if (resizingColumnKey == null) {
    columnWidthsRef.current = columnWidths
  }

  useEffect(() => {
    setColumnWidths((prev) => {
      const next: Record<string, number> = {}
      let changed = false
      for (const key of columnKeys) {
        if (typeof prev[key] === 'number') {
          next[key] = clampColumnWidth(prev[key])
          changed ||= next[key] !== prev[key]
        }
      }
      changed ||= Object.keys(prev).length !== Object.keys(next).length
      return changed ? next : prev
    })
  }, [columnKeys])

  const getColumnWidthPx = useCallback(
    (columnKey: string) => {
      const width = columnWidths[columnKey]
      if (typeof width === 'number' && Number.isFinite(width)) {
        return clampColumnWidth(width)
      }
      return Math.max(resizableColumnDefaultWidthPx, minColumnWidthPx)
    },
    [columnWidths, minColumnWidthPx],
  )

  const tableMinWidthPx = useMemo(() => {
    let total = showRowNumbers ? rowNumberColumnWidthPx : 0
    for (const key of columnKeys) {
      total += getColumnWidthPx(key)
    }
    return total
  }, [columnKeys, getColumnWidthPx, showRowNumbers])

  const repositionRails = useCallback(() => {
    const layer = tableLayerRef.current
    if (!layer) return
    for (const columnKey of columnKeysRef.current) {
      const th = headerThRefs.current.get(columnKey)
      const rail = railRefs.current.get(columnKey)
      if (!th || !rail) continue
      applyColumnResizeRailPosition(rail, layer, th)
    }
  }, [])

  const applyDraggedWidthPx = useCallback(
    (columnKey: string, widthPx: number) => {
      const next = clampColumnWidth(Math.round(widthPx))
      const isLastColumn =
        columnKeysRef.current[columnKeysRef.current.length - 1] === columnKey
      columnWidthsRef.current = {
        ...columnWidthsRef.current,
        [columnKey]: next,
      }
      const colEl = colRefs.current.get(columnKey)
      if (colEl) {
        colEl.style.width = `${next}px`
        colEl.style.minWidth = `${next}px`
        colEl.style.maxWidth = isLastColumn ? '' : `${next}px`
      }
      const thEl = headerThRefs.current.get(columnKey)
      if (thEl) {
        thEl.style.width = `${next}px`
        thEl.style.minWidth = `${next}px`
        thEl.style.maxWidth = isLastColumn ? '' : `${next}px`
      }
      repositionRails()
    },
    [repositionRails],
  )

  const handleResizePointerDown = useCallback(
    (columnKey: string) => (event: ReactPointerEvent<HTMLButtonElement>) => {
      if (!columnKey) return
      event.preventDefault()
      event.stopPropagation()
      setBodyResizeDragActive(true)
      const button = event.currentTarget
      button.setPointerCapture(event.pointerId)
      const startX = event.clientX
      const initialWidth = getColumnWidthPx(columnKey)
      setResizingColumnKey(columnKey)
      applyDraggedWidthPx(columnKey, initialWidth)
      const onMove = (ev: PointerEvent) => {
        applyDraggedWidthPx(
          columnKey,
          initialWidth + horizontalResizeDeltaPx(startX, ev.clientX),
        )
      }
      const onUp = () => {
        setBodyResizeDragActive(false)
        try {
          button.releasePointerCapture(event.pointerId)
        } catch {
          /* already released */
        }
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        window.removeEventListener('pointercancel', onUp)
        setResizingColumnKey(null)
        setColumnWidths({ ...columnWidthsRef.current })
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      window.addEventListener('pointercancel', onUp)
    },
    [applyDraggedWidthPx, getColumnWidthPx],
  )

  const openCellValueDialog = useCallback(
    (params: SpreadsheetCellValueDialogState) => {
      setCellValueDialog(params)
    },
    [],
  )

  const columnResizeLayoutKey = columnKeys.join('\u0001')

  useLayoutEffect(() => {
    if (!enableColumnResize) return
    repositionRails()
  }, [columnResizeLayoutKey, columnWidths, enableColumnResize, repositionRails])

  useLayoutEffect(() => {
    if (!enableColumnResize) return
    const scroll = scrollRef.current
    const layer = tableLayerRef.current
    if (!scroll || !layer || columnKeysRef.current.length === 0) return

    const measure = () => {
      repositionRails()
    }

    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(scroll)
    ro.observe(layer)
    scroll.addEventListener('scroll', measure, { passive: true })
    window.addEventListener('resize', measure)
    return () => {
      ro.disconnect()
      scroll.removeEventListener('scroll', measure)
      window.removeEventListener('resize', measure)
    }
  }, [columnResizeLayoutKey, columnWidths, enableColumnResize, repositionRails])

  if (isLoading && rows.length === 0) {
    return (
      <div
        className={cn(
          'flex h-full min-h-[12rem] items-center justify-center text-[13px] text-muted-foreground',
          className,
        )}
      >
        {t(loadingLabel)}
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

      <div ref={scrollRef} className="min-h-0 flex-1 overflow-auto">
        {normalizedColumns.length === 0 || rows.length === 0 ? (
          emptyContent ? (
            <div className="flex h-full min-h-[12rem] items-center justify-center px-4">
              {emptyContent}
            </div>
          ) : (
            <div className="flex h-full min-h-[12rem] items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
              {t(emptyLabel)}
            </div>
          )
        ) : (
          <div
            ref={tableLayerRef}
            className={cn(
              SPREADSHEET_SCROLL_LAYER_CLASS,
              !enableColumnResize && 'min-w-max',
            )}
            style={enableColumnResize ? { minWidth: tableMinWidthPx } : undefined}
          >
            <table
              className={cn(
                'relative z-0 w-full border-collapse text-start',
                enableColumnResize ? 'table-fixed' : 'min-w-max',
              )}
            >
              <colgroup>
                {showRowNumbers ? (
                  <col
                    style={{
                      width: rowNumberColumnWidthPx,
                      minWidth: rowNumberColumnWidthPx,
                      maxWidth: rowNumberColumnWidthPx,
                    }}
                  />
                ) : null}
                {normalizedColumns.map((column, columnIndex) => {
                  const width = getColumnWidthPx(column.key)
                  const isDragResize = resizingColumnKey === column.key
                  const isLastColumn = columnIndex === normalizedColumns.length - 1
                  return (
                    <col
                      key={column.key}
                      ref={(node) => {
                        if (node) colRefs.current.set(column.key, node)
                        else colRefs.current.delete(column.key)
                      }}
                      style={
                        enableColumnResize
                          ? isDragResize
                            ? undefined
                            : isLastColumn
                              ? { minWidth: width }
                              : { width, minWidth: width, maxWidth: width }
                          : { minWidth: `${minColumnWidthPx}px` }
                      }
                    />
                  )
                })}
              </colgroup>
              <thead className={stickyTheadClass}>
                <tr>
                  {showRowNumbers ? (
                    <th
                      className={cn(
                        'w-12 px-3 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground',
                        headerCellBorderClass,
                      )}
                      style={{
                        width: rowNumberColumnWidthPx,
                        minWidth: rowNumberColumnWidthPx,
                        maxWidth: rowNumberColumnWidthPx,
                      }}
                    >
                      #
                    </th>
                  ) : null}
                  {normalizedColumns.map((column, columnIndex) => {
                    const width = getColumnWidthPx(column.key)
                    const isDragResize = resizingColumnKey === column.key
                    const isLastColumn =
                      columnIndex === normalizedColumns.length - 1
                    return (
                      <th
                        key={column.key}
                        ref={(node) => {
                          if (node) headerThRefs.current.set(column.key, node)
                          else headerThRefs.current.delete(column.key)
                        }}
                        className={cn(
                          isStudio
                            ? 'px-4 py-3 text-start text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                            : 'px-3 py-2 text-start text-[12px] font-medium text-foreground',
                          headerCellBorderClass,
                        )}
                        style={
                          enableColumnResize
                            ? isDragResize
                              ? undefined
                              : isLastColumn
                                ? { minWidth: width }
                                : { width, minWidth: width, maxWidth: width }
                            : undefined
                        }
                      >
                        <span className="block truncate">{column.label}</span>
                      </th>
                    )
                  })}
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
                          <span className="block text-end text-[11px] tabular-nums text-muted-foreground">
                            {rowNumberOffset + rowIndex + 1}
                          </span>
                        </td>
                      ) : null}
                      {normalizedColumns.map((column) => {
                        const { full, display, isNull } = formatSpreadsheetCellValue(
                          row[column.key],
                        )
                        const isRtl = isSpreadsheetRtlText(full)
                        const rowNumber = rowNumberOffset + rowIndex + 1
                        const cell = (
                          <td
                            data-column={column.key}
                            onClick={
                              showCellContextMenu
                                ? () =>
                                    openCellValueDialog({
                                      columnLabel: column.label,
                                      rowNumber,
                                      value: row[column.key],
                                      full,
                                    })
                                : undefined
                            }
                            className={cn(
                              isStudio ? 'px-4 py-2.5' : 'px-3 py-1.5',
                              bodyCellBorderClass,
                              showCellContextMenu && 'cursor-pointer',
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

                        if (!showCellContextMenu) {
                          return cloneElement(cell, { key: column.key })
                        }

                        return (
                          <SpreadsheetCellContextMenu
                            key={column.key}
                            value={row[column.key]}
                            full={full}
                            display={display}
                            isNull={isNull}
                            onViewFullValue={() =>
                              openCellValueDialog({
                                columnLabel: column.label,
                                rowNumber,
                                value: row[column.key],
                                full,
                              })
                            }
                          >
                            {cell}
                          </SpreadsheetCellContextMenu>
                        )
                      })}
                    </tr>
                  )
                })}
              </tbody>
            </table>
            {enableColumnResize ? (
              <div className={COLUMN_RESIZE_RAILS_LAYER_CLASS} aria-hidden>
                {normalizedColumns.map((column) => (
                  <button
                    key={`col-resize-rail-${column.key}`}
                    ref={(node) => {
                      if (node) railRefs.current.set(column.key, node)
                      else railRefs.current.delete(column.key)
                    }}
                    type="button"
                    aria-label={`Resize ${column.label} column width`}
                    aria-orientation="vertical"
                    role="separator"
                    tabIndex={0}
                    onPointerDown={handleResizePointerDown(column.key)}
                    className={cn(
                      dataColumnResizeRailHandleClass,
                      resizingColumnKey === column.key && 'before:opacity-100',
                      'focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                    )}
                  />
                ))}
              </div>
            ) : null}
          </div>
        )}
      </div>

      {footer ? (
        <div className="h-[54px] shrink-0 border-t border-border bg-background">
          <div className="@container flex h-full items-center px-4 sm:px-6">
            <div className="min-w-0 flex-1">{footer}</div>
          </div>
        </div>
      ) : null}

      <SpreadsheetCellValueDialog
        state={cellValueDialog}
        onOpenChange={(open) => {
          if (!open) setCellValueDialog(null)
        }}
      />
    </div>
  )
}
