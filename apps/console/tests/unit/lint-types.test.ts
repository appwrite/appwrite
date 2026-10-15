import { describe, expect, test } from 'bun:test'
import { canonical } from '../../scripts/lint-types'

describe('type error message canonical form', () => {
  test('ignores the order of union members', () => {
    const before = `TS2820: Type '"/a/"' is not assignable to type '"." | "/privacy" | "/blog"'. Did you mean '"/a"'?`
    const after = `TS2820: Type '"/a/"' is not assignable to type '"/blog" | "." | "/privacy"'. Did you mean '"/a"'?`

    expect(canonical(before)).toBe(canonical(after))
  })

  test('keeps unions with different members apart', () => {
    expect(
      canonical(`TS2322: Type 'x' is not assignable to type '"/a" | "/b"'.`),
    ).not.toBe(
      canonical(`TS2322: Type 'x' is not assignable to type '"/a" | "/c"'.`),
    )
  })

  test('only splits top-level members', () => {
    expect(canonical(`TS2322: Type 'Set<"b" | "a"> | () => void'.`)).toBe(
      `TS2322: Type '() => void | Set<"b" | "a">'.`,
    )
  })

  test('leaves messages without unions unchanged', () => {
    const message = `TS2339: Property 'prefs' does not exist on type '{}'.`

    expect(canonical(message)).toBe(message)
  })
})
