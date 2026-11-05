import type { CoverRenderData } from '@/lib/cover-generator/types'

export const COVER_TABLE_GRID = {
  columns: { min: 2, max: 5, default: 4 },
  rows: { min: 1, max: 6, default: 4 },
} as const

export const COVER_TABLE_MAX_COLUMNS = COVER_TABLE_GRID.columns.max
export const COVER_TABLE_MAX_ROWS = COVER_TABLE_GRID.rows.max

export const COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT = 94

export const COVER_TABLE_DEFAULT_HEADERS = [
  'Feature',
  'Free',
  'Pro',
  'Scale',
  'Enterprise',
] as const

export const COVER_TABLE_DEFAULT_ROWS = [
  ['Storage', '2 GB', '150 GB', 'Unlimited', 'Unlimited'],
  ['Users', '75K MAU', '200K MAU', '1M MAU', 'Custom'],
  ['Functions', '750K / mo', '3.5M / mo', '10M / mo', 'Custom'],
  ['Support', 'Community', 'Priority', 'Dedicated', 'Dedicated'],
  ['', '', '', '', ''],
  ['', '', '', '', ''],
] as const

export type CoverTableHeaderKey = `header${number}`
export type CoverTableCellKey = `cell_r${number}c${number}`

export function getCoverTableHeaderKey(col: number): CoverTableHeaderKey {
  return `header${col}`
}

export function getCoverTableCellKey(row: number, col: number): CoverTableCellKey {
  return `cell_r${row}c${col}`
}

export function getCoverTableHeaderKeys(count = COVER_TABLE_MAX_COLUMNS): CoverTableHeaderKey[] {
  return Array.from({ length: count }, (_, col) => getCoverTableHeaderKey(col))
}

export function getCoverTableCellKeys(
  rows = COVER_TABLE_MAX_ROWS,
  columns = COVER_TABLE_MAX_COLUMNS,
): CoverTableCellKey[] {
  const keys: CoverTableCellKey[] = []
  for (let row = 0; row < rows; row += 1) {
    for (let col = 0; col < columns; col += 1) {
      keys.push(getCoverTableCellKey(row, col))
    }
  }
  return keys
}

export function buildCoverTableDefaultFieldParams(): Record<string, string> {
  const params: Record<string, string> = {}

  for (let col = 0; col < COVER_TABLE_MAX_COLUMNS; col += 1) {
    params[getCoverTableHeaderKey(col)] = COVER_TABLE_DEFAULT_HEADERS[col] ?? ''
  }

  for (let row = 0; row < COVER_TABLE_MAX_ROWS; row += 1) {
    for (let col = 0; col < COVER_TABLE_MAX_COLUMNS; col += 1) {
      params[getCoverTableCellKey(row, col)] =
        COVER_TABLE_DEFAULT_ROWS[row]?.[col] ?? ''
    }
  }

  return params
}

export type CoverTableMatrix = {
  headers: string[]
  rows: string[][]
}

export function normalizeCoverTableData(
  data: CoverRenderData,
): Extract<CoverRenderData, { template: 'table' }> {
  const tableData = data.template === 'table' ? data : null
  const tableValues =
    data.template === 'table' ? (data as Record<string, string | undefined>) : null
  const columns =
    tableData && Number.isFinite(tableData.columns)
      ? Math.min(
          COVER_TABLE_GRID.columns.max,
          Math.max(COVER_TABLE_GRID.columns.min, tableData.columns),
        )
      : COVER_TABLE_GRID.columns.default

  const rows =
    tableData && Number.isFinite(tableData.rows)
      ? Math.min(
          COVER_TABLE_GRID.rows.max,
          Math.max(COVER_TABLE_GRID.rows.min, tableData.rows),
        )
      : COVER_TABLE_GRID.rows.default

  const defaults = buildCoverTableDefaultFieldParams()
  const headerFields = Object.fromEntries(
    getCoverTableHeaderKeys(columns).map((key, col) => [
      key,
      tableValues?.[key]?.trim() || defaults[key] || COVER_TABLE_DEFAULT_HEADERS[col] || '',
    ]),
  )

  const cellFields = Object.fromEntries(
    Array.from({ length: rows }, (_, row) =>
      Array.from({ length: columns }, (_, col) => {
        const key = getCoverTableCellKey(row, col)
        return [key, tableValues?.[key]?.trim() || defaults[key] || '']
      }),
    ).flat(),
  )

  return {
    theme: data.theme,
    format: data.format,
    width: data.width,
    height: data.height,
    template: 'table',
    title: tableData?.title?.trim() || undefined,
    subtitle: tableData?.subtitle?.trim() || undefined,
    columns,
    rows,
    showHeader: tableData?.showHeader == null ? true : Boolean(tableData.showHeader),
    frameWidthPercent:
      tableData && Number.isFinite(tableData.frameWidthPercent)
        ? tableData.frameWidthPercent
        : COVER_TABLE_DEFAULT_FRAME_WIDTH_PERCENT,
    ...headerFields,
    ...cellFields,
  }
}

export function getCoverTableMatrix(
  data: Extract<CoverRenderData, { template: 'table' }>,
): CoverTableMatrix {
  const normalized = normalizeCoverTableData(data)

  return {
    headers: Array.from({ length: normalized.columns }, (_, col) => {
      const key = getCoverTableHeaderKey(col)
      return normalized[key] ?? ''
    }),
    rows: Array.from({ length: normalized.rows }, (_, row) =>
      Array.from({ length: normalized.columns }, (_, col) => {
        const key = getCoverTableCellKey(row, col)
        return normalized[key] ?? ''
      }),
    ),
  }
}
