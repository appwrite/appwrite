import { describe, expect, test } from 'bun:test'
import {
  canAdoptConsoleSqlApiStatements,
  CONSOLE_SQL_API_ALLOWED_STATEMENTS,
  hasConsoleSqlApiStatements,
  isSqlApiDdlBlockedError,
} from '@/lib/databases/sql-api-statements'

describe('hasConsoleSqlApiStatements', () => {
  test('rejects the API default DML-only allow-list', () => {
    expect(
      hasConsoleSqlApiStatements({
        sqlApiAllowedStatements: ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
      }),
    ).toBe(false)
  })

  test('rejects an empty or missing list', () => {
    expect(hasConsoleSqlApiStatements({ sqlApiAllowedStatements: [] })).toBe(
      false,
    )
    expect(
      hasConsoleSqlApiStatements({ sqlApiAllowedStatements: undefined }),
    ).toBe(false)
    expect(hasConsoleSqlApiStatements(null)).toBe(false)
  })

  test('accepts the full console allow-list, case-insensitively', () => {
    expect(
      hasConsoleSqlApiStatements({
        sqlApiAllowedStatements: CONSOLE_SQL_API_ALLOWED_STATEMENTS,
      }),
    ).toBe(true)
    expect(
      hasConsoleSqlApiStatements({
        sqlApiAllowedStatements: CONSOLE_SQL_API_ALLOWED_STATEMENTS.map(
          (type) => type.toLowerCase(),
        ),
      }),
    ).toBe(true)
  })
})

describe('isSqlApiDdlBlockedError', () => {
  test('matches the PostgreSQL read-only transaction error', () => {
    expect(
      isSqlApiDdlBlockedError(
        new Error(
          'query error: cannot execute CREATE TABLE in a read-only transaction',
        ),
      ),
    ).toBe(true)
  })

  test('matches an allow-list rejection', () => {
    expect(
      isSqlApiDdlBlockedError(
        new Error('Statement type CREATE is not allowed'),
      ),
    ).toBe(true)
  })

  test('ignores unrelated query errors', () => {
    expect(
      isSqlApiDdlBlockedError(new Error('relation "users" already exists')),
    ).toBe(false)
  })
})

describe('canAdoptConsoleSqlApiStatements', () => {
  test('adopts the SQL API default list', () => {
    expect(
      canAdoptConsoleSqlApiStatements({
        sqlApiEnabled: true,
        sqlApiAllowedStatements: ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
      }),
    ).toBe(true)
    expect(
      canAdoptConsoleSqlApiStatements({
        sqlApiEnabled: true,
        sqlApiAllowedStatements: [],
      }),
    ).toBe(true)
  })

  test('keeps a list someone narrowed or changed', () => {
    expect(
      canAdoptConsoleSqlApiStatements({
        sqlApiEnabled: true,
        sqlApiAllowedStatements: ['SELECT'],
      }),
    ).toBe(false)
    expect(
      canAdoptConsoleSqlApiStatements({
        sqlApiEnabled: true,
        sqlApiAllowedStatements: [
          'SELECT',
          'INSERT',
          'UPDATE',
          'DELETE',
          'CREATE',
        ],
      }),
    ).toBe(false)
  })

  test('keeps a disabled SQL API disabled unless the console is enabling it', () => {
    const database = {
      sqlApiEnabled: false,
      sqlApiAllowedStatements: ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
    }

    expect(canAdoptConsoleSqlApiStatements(database)).toBe(false)
    expect(canAdoptConsoleSqlApiStatements(database, true)).toBe(true)
  })

  test('skips a database that already has the console list', () => {
    expect(
      canAdoptConsoleSqlApiStatements({
        sqlApiEnabled: true,
        sqlApiAllowedStatements: CONSOLE_SQL_API_ALLOWED_STATEMENTS,
      }),
    ).toBe(false)
  })
})
