import { describe, expect, test } from 'bun:test'
import { formatPrefValue, formatPrefsForEditor } from '@/lib/prefs-value'

describe('formatPrefValue', () => {
  test('passes strings through', () => {
    expect(formatPrefValue('admin')).toBe('admin')
    expect(formatPrefValue('')).toBe('')
  })

  test('keeps a stored null editable so a save does not drop the key', () => {
    // Empty values are excluded from the prefs object the editors submit.
    expect(formatPrefValue(null)).toBe('null')
    expect(formatPrefValue(undefined)).toBe('')
  })

  test('stringifies primitives', () => {
    expect(formatPrefValue(42)).toBe('42')
    expect(formatPrefValue(true)).toBe('true')
    expect(formatPrefValue(10n)).toBe('10')
  })

  test('serializes objects and arrays as JSON', () => {
    expect(formatPrefValue({ tier: 'pro' })).toBe('{"tier":"pro"}')
    expect(formatPrefValue(['a', 'b'])).toBe('["a","b"]')
  })

  test('does not throw on values that cannot be coerced to a primitive', () => {
    // `String()` on these throws "Cannot convert object to primitive value".
    const shadowedToString = JSON.parse('{"toString":"not a function"}')
    expect(() => String(shadowedToString)).toThrow()
    expect(formatPrefValue(shadowedToString)).toBe(
      '{"toString":"not a function"}',
    )

    const nullPrototype = Object.assign(Object.create(null), { a: 1 })
    expect(() => String(nullPrototype)).toThrow()
    expect(formatPrefValue(nullPrototype)).toBe('{"a":1}')
  })

  test('falls back to an empty string for unserializable objects', () => {
    const circular: Record<string, unknown> = {}
    circular.self = circular
    expect(formatPrefValue(circular)).toBe('')
  })
})

describe('formatPrefsForEditor', () => {
  test('normalizes every value to a string', () => {
    expect(
      formatPrefsForEditor({
        name: 'Bob',
        age: 30,
        meta: { role: 'admin' },
        empty: null,
      }),
    ).toEqual({
      name: 'Bob',
      age: '30',
      meta: '{"role":"admin"}',
      empty: 'null',
    })
  })
})
