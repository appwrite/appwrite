import { describe, expect, test } from 'bun:test'
import {
  getPreferredSqlColumnDefaultKind,
  parseMysqlStoredColumnDefault,
  parsePostgresStoredColumnDefault,
  sqlColumnDefaultsEqual,
  storedColumnDefaultForForm,
} from '@/lib/sql-column-default'

describe('getPreferredSqlColumnDefaultKind', () => {
  test('uses expression for timestamps, uuid, and json', () => {
    expect(
      getPreferredSqlColumnDefaultKind({
        typeGroup: 'Date & time',
        typeId: 'timestamp',
      }),
    ).toBe('expression')
    expect(
      getPreferredSqlColumnDefaultKind({ typeGroup: 'Structured', typeId: 'uuid' }),
    ).toBe('expression')
    expect(
      getPreferredSqlColumnDefaultKind({ typeGroup: 'Text', typeId: 'text' }),
    ).toBe('value')
  })
})

describe('parsePostgresStoredColumnDefault', () => {
  test('unwraps text literals including casts', () => {
    expect(parsePostgresStoredColumnDefault("'hello'::text")).toEqual({
      kind: 'value',
      value: 'hello',
    })
    expect(parsePostgresStoredColumnDefault("'o''reilly'")).toEqual({
      kind: 'value',
      value: "o'reilly",
    })
  })

  test('keeps functions and jsonb casts as expressions', () => {
    expect(parsePostgresStoredColumnDefault('now()')).toEqual({
      kind: 'expression',
      value: 'now()',
    })
    expect(parsePostgresStoredColumnDefault("'{}'::jsonb")).toEqual({
      kind: 'expression',
      value: "'{}'::jsonb",
    })
  })
})

describe('parseMysqlStoredColumnDefault', () => {
  test('treats unquoted information_schema values as values', () => {
    expect(parseMysqlStoredColumnDefault('hello')).toEqual({
      kind: 'value',
      value: 'hello',
    })
  })

  test('unwraps quoted TEXT defaults and keeps CURRENT_TIMESTAMP as an expression', () => {
    expect(parseMysqlStoredColumnDefault("('eldad')")).toEqual({
      kind: 'value',
      value: 'eldad',
    })
    expect(parseMysqlStoredColumnDefault('CURRENT_TIMESTAMP')).toEqual({
      kind: 'expression',
      value: 'CURRENT_TIMESTAMP',
    })
  })

  test('treats missing and NULL defaults as null', () => {
    expect(parseMysqlStoredColumnDefault(null)).toEqual({
      kind: 'value',
      value: '',
      isNull: true,
    })
    expect(parseMysqlStoredColumnDefault('NULL')).toEqual({
      kind: 'value',
      value: '',
      isNull: true,
    })
  })
})

describe('sqlColumnDefaultsEqual', () => {
  test('treats empty defaults as equal regardless of kind', () => {
    expect(
      sqlColumnDefaultsEqual(
        { kind: 'value', value: '' },
        { kind: 'expression', value: '' },
      ),
    ).toBe(true)
  })
})

describe('storedColumnDefaultForForm', () => {
  test('uses the preferred kind when the stored default is empty', () => {
    expect(
      storedColumnDefaultForForm({ kind: 'value', value: '' }, 'expression'),
    ).toEqual({ kind: 'expression', value: '' })
  })

  test('keeps null defaults as null with the preferred kind', () => {
    expect(
      storedColumnDefaultForForm(
        { kind: 'value', value: '', isNull: true },
        'expression',
      ),
    ).toEqual({ kind: 'expression', value: '', isNull: true })
  })
})
