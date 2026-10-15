import { describe, expect, test } from 'bun:test'
import { parseBlockResourceIds } from '@/components/pages/blocks/parse-resource-ids'

describe('parseBlockResourceIds', () => {
  test('returns nothing for blank input', () => {
    expect(parseBlockResourceIds('')).toEqual({ ids: [], duplicateCount: 0 })
    expect(parseBlockResourceIds(' \n\t ')).toEqual({
      ids: [],
      duplicateCount: 0,
    })
  })

  test('keeps a single id', () => {
    expect(parseBlockResourceIds('fn_123')).toEqual({
      ids: ['fn_123'],
      duplicateCount: 0,
    })
  })

  test('splits on newlines, commas, semicolons, and spaces', () => {
    expect(parseBlockResourceIds('a\nb, c;d\r\ne\tf  g')).toEqual({
      ids: ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
      duplicateCount: 0,
    })
  })

  test('drops duplicates and keeps the first occurrence', () => {
    expect(parseBlockResourceIds('a\nb\na\nb')).toEqual({
      ids: ['a', 'b'],
      duplicateCount: 2,
    })
  })

  test('strips wrapping quotes', () => {
    expect(parseBlockResourceIds('"abc"\n\'def\'')).toEqual({
      ids: ['abc', 'def'],
      duplicateCount: 0,
    })
  })
})
