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
