import { describe, expect, test } from 'bun:test'
import {
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
    expect(hasConsoleSqlApiStatements({ sqlApiAllowedStatements: undefined })).toBe(
      false,
    )
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
        sqlApiAllowedStatements: CONSOLE_SQL_API_ALLOWED_STATEMENTS.map((type) =>
          type.toLowerCase(),
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
