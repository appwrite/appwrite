import { parsePostgresIndexKeyColumns } from '@/lib/postgres-index-metadata'
import {
  RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
} from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'
import type { CSSProperties } from 'react'

export const POSTGRES_ROWS_TABLE_EDGE_COL_PX = 40
export const POSTGRES_ROWS_DATA_COLUMN_DEFAULT_WIDTH_PX = 150
export const POSTGRES_ROWS_DATA_COLUMN_MIN_WIDTH_PX = 72
export const POSTGRES_ROWS_DATA_COLUMN_MAX_WIDTH_PX = 640

/** Sum of edge column + data column minimum widths (for table minWidth / horizontal scroll). */
export function computePostgresRowsTableWidthPx(
  columnKeys: readonly string[],
  getColumnWidthPx: (columnKey: string) => number,
): number {
  let total = POSTGRES_ROWS_TABLE_EDGE_COL_PX
  for (const key of columnKeys) {
    if (!key) continue
    total += getColumnWidthPx(key)
  }
  return total
}

export function getPostgresRowsDataColumnColStyle(
  columnIndex: number,
  columnCount: number,
  widthPx: number,
  isDragResize: boolean,
): CSSProperties | undefined {
  if (isDragResize) return undefined
  const isLast = columnIndex === columnCount - 1
  if (isLast) {
    return { minWidth: widthPx }
  }
  return { width: widthPx, minWidth: widthPx, maxWidth: widthPx }
}

export function getPostgresRowsDataColumnHeaderStyle(
  columnIndex: number,
  columnCount: number,
  widthPx: number,
  isDragResize: boolean,
): CSSProperties | undefined {
  if (isDragResize) return undefined
  const isLast = columnIndex === columnCount - 1
  if (isLast) {
    return { minWidth: widthPx }
  }
  return {
    width: widthPx,
    minWidth: widthPx,
    maxWidth: widthPx,
  }
}

export const POSTGRES_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS = cn(
  'group absolute top-0 bottom-0 z-[41] w-2 cursor-col-resize touch-none border-0 bg-transparent p-0 outline-none',
  'after:pointer-events-none after:absolute after:inset-y-0 after:w-[0.5px] after:bg-border',
  RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
  'before:pointer-events-none before:absolute before:inset-y-0 before:z-10 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
  'hover:before:opacity-100',
)

export const POSTGRES_STICKY_THEAD_CLASS = 'sticky top-0 z-20 bg-background'
export const POSTGRES_HEADER_CELL_BORDER_CLASS =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
export const POSTGRES_BODY_CELL_BORDER_CLASS = 'border-b border-e border-border'
export const POSTGRES_LAST_CELL_BORDER_CLASS = 'border-b border-border'

export function getPostgresColumnTypeColor(type: string): string {
  const normalized = type.toLowerCase()
  const colors: Record<string, string> = {
    text: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    varchar:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    bpchar:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
    int2: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
    int4: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
    int8: 'bg-violet-500/10 text-violet-600 dark:text-violet-400 border-violet-500/20',
    integer:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
    bigint:
      'bg-violet-500/10 text-violet-600 dark:text-violet-400 border-violet-500/20',
    numeric:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
    float4:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
    float8:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
    bool: 'bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20',
    boolean:
      'bg-green-500/10 text-green-600 dark:text-green-400 border-green-500/20',
    timestamp:
      'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
    timestamptz:
      'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
    date: 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
    uuid: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border-cyan-500/20',
    jsonb: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    json: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
  }
  return colors[normalized] || 'bg-muted text-muted-foreground border-border'
}

export function getPostgresIndexTypeColor(type: string): string {
  const colors: Record<string, string> = {
    primary:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    unique:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
    key: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
  }
  return colors[type] || 'bg-muted text-muted-foreground border-border'
}

export function matchesPostgresLocalSearch(
  query: string,
  ...parts: Array<string | null | undefined>
): boolean {
  const normalized = query.trim().toLowerCase()
  if (!normalized) return true
  return parts.some((part) => part?.toLowerCase().includes(normalized))
}

export function parsePostgresIndexColumnsFromDefinition(
  definition: string,
): string[] {
  return parsePostgresIndexKeyColumns(definition)
}
