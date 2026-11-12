import type { Node } from '@markdoc/markdoc'

/** Read `{% width=N %}` values from the table header row in the Markdoc AST. */
export function extractMarkdocTableColumnWidthsFromAst(
  tableNode: Node,
): Array<number | undefined> {
  const widths: Array<number | undefined> = []
  const thead = tableNode.children?.find((child) => child.type === 'thead')
  const row = thead?.children?.find((child) => child.type === 'tr')
  if (!row?.children) return widths

  row.children.forEach((cell, index) => {
    if (cell.type !== 'th') return
    if (widths.length <= index) {
      widths.length = index + 1
    }
    const width = cell.attributes?.width
    if (typeof width === 'number') {
      widths[index] = width
    }
  })

  return Array.from({ length: widths.length }, (_, index) => widths[index])
}

/** Narrowest a column without `{% width %}` gets before the table scrolls. */
export const MARKDOC_TABLE_FILL_MIN_WIDTH = 192

/**
 * Minimum table width when some columns are fixed, so fill columns keep room
 * for their text on small screens and the table scrolls instead.
 */
export function markdocTableMinWidth(
  columnWidths: Array<number | undefined>,
): number | undefined {
  const fixed = columnWidths.filter((width) => width != null)
  if (fixed.length === 0) return undefined

  const fillCount = columnWidths.length - fixed.length
  return (
    fixed.reduce((sum, width) => sum + width, 0) +
    fillCount * MARKDOC_TABLE_FILL_MIN_WIDTH
  )
}
