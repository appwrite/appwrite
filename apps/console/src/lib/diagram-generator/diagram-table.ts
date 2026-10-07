import type { DiagramNode } from '@/lib/diagram-generator/types'

export const DIAGRAM_TABLE_MIN_COLUMNS = 2
export const DIAGRAM_TABLE_MAX_COLUMNS = 6
export const DIAGRAM_TABLE_MIN_ROWS = 2
export const DIAGRAM_TABLE_MAX_ROWS = 8

export function createDefaultDiagramTable(): {
  tableHeaders: string[]
  tableRows: string[][]
} {
  return {
    tableHeaders: ['Column A', 'Column B', 'Column C'],
    tableRows: [
      ['Value', 'Value', 'Value'],
      ['Value', 'Value', 'Value'],
    ],
  }
}

function normalizeCellValue(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value.slice(0, 48) : fallback
}

export function normalizeDiagramTableData(
  headers: unknown,
  rows: unknown,
): { tableHeaders: string[]; tableRows: string[][] } {
  const defaults = createDefaultDiagramTable()
  const sourceHeaders = Array.isArray(headers) ? headers : defaults.tableHeaders
  const columnCount = Math.min(
    DIAGRAM_TABLE_MAX_COLUMNS,
    Math.max(
      DIAGRAM_TABLE_MIN_COLUMNS,
      sourceHeaders.length || defaults.tableHeaders.length,
    ),
  )

  const tableHeaders = Array.from({ length: columnCount }, (_, index) =>
    normalizeCellValue(sourceHeaders[index], `Column ${index + 1}`),
  )

  const sourceRows = Array.isArray(rows) ? rows : defaults.tableRows
  const rowCount = Math.min(
    DIAGRAM_TABLE_MAX_ROWS,
    Math.max(DIAGRAM_TABLE_MIN_ROWS, sourceRows.length || defaults.tableRows.length),
  )

  const tableRows = Array.from({ length: rowCount }, (_, rowIndex) => {
    const sourceRow = Array.isArray(sourceRows[rowIndex]) ? sourceRows[rowIndex] : []
    return Array.from({ length: columnCount }, (_, columnIndex) =>
      normalizeCellValue(sourceRow[columnIndex], 'Value'),
    )
  })

  return { tableHeaders, tableRows }
}

export function normalizeDiagramTableNode(
  node: DiagramNode,
): Pick<DiagramNode, 'tableHeaders' | 'tableRows'> {
  if (node.kind !== 'table') {
    return { tableHeaders: undefined, tableRows: undefined }
  }

  return normalizeDiagramTableData(node.tableHeaders, node.tableRows)
}

export function resizeDiagramTableColumns(
  headers: string[],
  rows: string[][],
  columnCount: number,
): { tableHeaders: string[]; tableRows: string[][] } {
  const nextCount = Math.min(
    DIAGRAM_TABLE_MAX_COLUMNS,
    Math.max(DIAGRAM_TABLE_MIN_COLUMNS, columnCount),
  )

  const tableHeaders = Array.from({ length: nextCount }, (_, index) =>
    headers[index] ?? `Column ${index + 1}`,
  )
  const tableRows = rows.map((row) =>
    Array.from({ length: nextCount }, (_, index) => row[index] ?? 'Value'),
  )

  return normalizeDiagramTableData(tableHeaders, tableRows)
}

export function resizeDiagramTableRows(
  headers: string[],
  rows: string[][],
  rowCount: number,
): { tableHeaders: string[]; tableRows: string[][] } {
  const nextCount = Math.min(
    DIAGRAM_TABLE_MAX_ROWS,
    Math.max(DIAGRAM_TABLE_MIN_ROWS, rowCount),
  )
  const columnCount = headers.length

  const tableRows = Array.from({ length: nextCount }, (_, rowIndex) => {
    if (rowIndex < rows.length) return [...rows[rowIndex]]
    return Array.from({ length: columnCount }, () => 'Value')
  })

  return normalizeDiagramTableData(headers, tableRows)
}

export function updateDiagramTableCell(
  headers: string[],
  rows: string[][],
  rowIndex: number,
  columnIndex: number,
  value: string,
): { tableHeaders: string[]; tableRows: string[][] } {
  if (rowIndex === -1) {
    const tableHeaders = headers.map((header, index) =>
      index === columnIndex ? value.slice(0, 48) : header,
    )
    return { tableHeaders, tableRows: rows }
  }

  const tableRows = rows.map((row, index) =>
    index === rowIndex
      ? row.map((cell, cellIndex) =>
          cellIndex === columnIndex ? value.slice(0, 48) : cell,
        )
      : row,
  )

  return { tableHeaders: headers, tableRows }
}
