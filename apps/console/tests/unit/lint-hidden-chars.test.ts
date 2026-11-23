import { describe, expect, test } from 'bun:test'
import {
  findHiddenCharsInText,
  fixHiddenChars,
} from '../../scripts/lint-hidden-chars'

describe('findHiddenCharsInText', () => {
  test('flags NBSP and narrow NBSP', () => {
    const hits = findHiddenCharsInText('hello\u00A0world\u202Fnow')
    expect(hits.map((hit) => hit.name)).toEqual(['NBSP', 'NARROW NBSP'])
    expect(hits[0]).toMatchObject({ line: 1, column: 6 })
  })

  test('flags zero-width space and BOM', () => {
    const hits = findHiddenCharsInText('\uFEFFfoo\u200Bbar')
    expect(hits.map((hit) => hit.name)).toEqual([
      'BOM / ZERO WIDTH NO-BREAK SPACE',
      'ZERO WIDTH SPACE',
    ])
  })

  test('does not flag Hebrew LTR/RTL marks', () => {
    expect(findHiddenCharsInText('file\u200E.tar.gz')).toEqual([])
    expect(findHiddenCharsInText('text\u200F')).toEqual([])
  })

  test('tracks line and column across newlines', () => {
    const hits = findHiddenCharsInText('one\ntwo\u00A0three')
    expect(hits[0]).toMatchObject({ line: 2, column: 4, name: 'NBSP' })
  })
})

describe('fixHiddenChars', () => {
  test('replaces NBSP with a normal space and strips zero-width chars', () => {
    expect(fixHiddenChars('a\u00A0b\u200Bc\uFEFFd')).toBe('a bcd')
  })
})
