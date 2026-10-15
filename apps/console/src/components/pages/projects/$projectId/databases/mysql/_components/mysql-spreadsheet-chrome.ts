import { parseMysqlIndexKeyColumns } from '@/lib/mysql-index-metadata'
import { columnResizeRailHandleClass } from '@/lib/layout/horizontal-resize'
import {
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import { cn } from '@/lib/utils'
import type { CSSProperties } from 'react'

export const MYSQL_ROWS_TABLE_EDGE_COL_PX = 40
export const MYSQL_ACTIONS_COL_PX = 40

export const MYSQL_ACTIONS_COL_STYLE = {
  width: MYSQL_ACTIONS_COL_PX,
  minWidth: MYSQL_ACTIONS_COL_PX,
  maxWidth: MYSQL_ACTIONS_COL_PX,
} as const

export const MYSQL_STICKY_ACTIONS_HEADER_CLASS = cn(
  'relative sticky end-0 z-30 bg-background p-0',
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
)

export function mysqlStickyActionsCellClass(options?: {
  mutedRow?: boolean
}): string {
  return cn(
    'sticky end-0 z-10 border-b border-border p-0',
    SPREADSHEET_STICKY_END_EDGE_SHADOW,
    options?.mutedRow
      ? 'bg-muted/30'
      : 'bg-background group-hover:bg-muted/50',
  )
}
export const MYSQL_ROWS_DATA_COLUMN_DEFAULT_WIDTH_PX = 150
export const MYSQL_ROWS_DATA_COLUMN_MIN_WIDTH_PX = 72
export const MYSQL_ROWS_DATA_COLUMN_MAX_WIDTH_PX = 640

/** Sum of edge column + data column minimum widths (for table minWidth / horizontal scroll). */
export function computeMysqlRowsTableWidthPx(
  columnKeys: readonly string[],
  getColumnWidthPx: (columnKey: string) => number,
): number {
  let total = MYSQL_ROWS_TABLE_EDGE_COL_PX
  for (const key of columnKeys) {
    if (!key) continue
    total += getColumnWidthPx(key)
  }
  return total
}

export function getMysqlRowsDataColumnColStyle(
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

export function getMysqlRowsDataColumnHeaderStyle(
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

export const MYSQL_DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS =
  columnResizeRailHandleClass()

export const MYSQL_STICKY_THEAD_CLASS = 'sticky top-0 z-20 bg-background'
export const MYSQL_HEADER_CELL_BORDER_CLASS =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
export const MYSQL_BODY_CELL_BORDER_CLASS = 'border-b border-e border-border'
export const MYSQL_LAST_CELL_BORDER_CLASS = 'border-b border-border'

export function getMysqlColumnTypeColor(type: string): string {
  const normalized = type.toLowerCase()
  if (normalized === 'enum' || normalized.startsWith('enum(')) {
    return 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400'
  }
  const colors: Record<string, string> = {
    text: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    varchar:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    bpchar:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    int2: 'bg-purple-500/10 text-purple-600 dark:text-purple-400',
    int4: 'bg-purple-500/10 text-purple-600 dark:text-purple-400',
    int8: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    integer:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400',
    bigint:
      'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    numeric:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    float4:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    float8:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    bool: 'bg-green-500/10 text-green-600 dark:text-green-400',
    boolean:
      'bg-green-500/10 text-green-600 dark:text-green-400',
    timestamp:
      'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    timestamptz:
      'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    date: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    uuid: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    jsonb: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    json: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  }
  return colors[normalized] || 'bg-muted text-muted-foreground'
}

export function getMysqlIndexTypeColor(type: string): string {
  const colors: Record<string, string> = {
    primary:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    unique:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    key: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  }
  return colors[type] || 'bg-muted text-muted-foreground'
}

/** Tinted badge class for enum literal values (matches column type badges). */
export function getMysqlEnumValueBadgeClass(): string {
  return 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400'
}

export function matchesMysqlLocalSearch(
  query: string,
  ...parts: Array<string | null | undefined>
): boolean {
  const normalized = query.trim().toLowerCase()
  if (!normalized) return true
  return parts.some((part) => part?.toLowerCase().includes(normalized))
}

export function parseMysqlIndexColumnsFromDefinition(
  definition: string,
): string[] {
  return parseMysqlIndexKeyColumns(definition)
}
