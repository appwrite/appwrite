import { describe, expect, test } from 'bun:test'
import {
  getSqlColumnCheckExample,
  isTextColumnComparedToNumber,
  uniqueNotNullUsesConstantDefault,
} from '@/lib/sql-column-form-hints'
import { getErrorMessage } from '@/lib/utils/error-formatting'

describe('sql column form hints', () => {
  test('uses the column name in check examples', () => {
    expect(getSqlColumnCheckExample('test4', 'Text')).toBe('length(test4) < 500')
    expect(getSqlColumnCheckExample('age', 'Integer')).toBe('age > 0')
  })

  test('detects text compared to an unquoted number', () => {
    expect(isTextColumnComparedToNumber('test4=5')).toBe(true)
    expect(isTextColumnComparedToNumber("test4 = '5'")).toBe(false)
    expect(isTextColumnComparedToNumber('length(test4) < 500')).toBe(false)
  })

  test('flags unique not-null columns with a constant default', () => {
    expect(
      uniqueNotNullUsesConstantDefault({
        unique: true,
        nullable: false,
        defaultValue: '',
        defaultKind: 'value',
      }),
    ).toBe(true)
    expect(
      uniqueNotNullUsesConstantDefault({
        unique: true,
        nullable: true,
        defaultValue: '',
        defaultKind: 'value',
      }),
    ).toBe(false)
    expect(
      uniqueNotNullUsesConstantDefault({
        unique: true,
        nullable: false,
        defaultValue: 'UUID()',
        defaultKind: 'expression',
      }),
    ).toBe(false)
  })
})

describe('database engine error formatting', () => {
  test('surfaces SQL errors even when the API status is 500', () => {
    const error = Object.assign(
      new Error("Unknown column 'test1234' in 'DEFAULT'"),
      { code: 500 },
    )
    expect(getErrorMessage(error)).toBe("Unknown column 'test1234' in 'DEFAULT'")
  })

  test('keeps the generic message for opaque server failures', () => {
    const error = Object.assign(new Error('Internal Server Error'), {
      code: 500,
    })
    expect(getErrorMessage(error)).toContain('An error occurred on the server')
  })

  test('explains duplicate empty unique values on existing rows', () => {
    const error = Object.assign(
      new Error("query error: Duplicate entry '' for key 'Hello.test3'"),
      { code: 500 },
    )
    expect(getErrorMessage(error)).toBe(
      'Existing rows were filled with an empty value, which is not unique. Allow NULL, or add the column first and fill distinct values.',
    )
  })
})
