import { describe, expect, test } from 'bun:test'
import { isValidKey } from '@/lib/add-app-wizard/platform-map'

describe('isValidKey', () => {
  test('accepts reverse-DNS package and bundle ids', () => {
    expect(isValidKey('com.example')).toBe(true)
    expect(isValidKey('io.appwrite.my_app-1')).toBe(true)
    expect(isValidKey('a.1b.c')).toBe(true)
  })

  test('rejects ids without a dotted segment that starts with a letter', () => {
    expect(isValidKey('example')).toBe(false)
    expect(isValidKey('1com.example')).toBe(false)
    expect(isValidKey('com.1')).toBe(false)
    expect(isValidKey('com.example!')).toBe(false)
    expect(isValidKey('a.')).toBe(false)
  })

  test('rejects a long invalid id without backtracking', () => {
    const started = performance.now()

    expect(isValidKey('A' + '.A'.repeat(20_000) + '!')).toBe(false)
    expect(performance.now() - started).toBeLessThan(1_000)
  })
})
