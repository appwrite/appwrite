import { describe, expect, test } from 'bun:test'
import {
  MARKDOC_TABLE_FILL_MIN_WIDTH,
  markdocTableMinWidth,
} from '@/lib/docs/markdoc-table'

describe('markdoc table min width', () => {
  test('leaves tables without fixed columns free to shrink', () => {
    expect(markdocTableMinWidth([])).toBeUndefined()
    expect(markdocTableMinWidth([undefined, undefined])).toBeUndefined()
  })

  test('keeps room for fill columns next to fixed ones', () => {
    expect(markdocTableMinWidth([300, undefined])).toBe(
      300 + MARKDOC_TABLE_FILL_MIN_WIDTH,
    )
    expect(markdocTableMinWidth([130, 260, undefined])).toBe(
      390 + MARKDOC_TABLE_FILL_MIN_WIDTH,
    )
  })

  test('uses the fixed widths alone when every column is fixed', () => {
    expect(markdocTableMinWidth([120, 80])).toBe(200)
  })
})
