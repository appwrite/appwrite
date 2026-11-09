import { describe, expect, test } from 'bun:test'
import { formatMysqlSqlLiteral } from '@/lib/mysql-row-sql'
import { quoteMysqlStringLiteral } from '@/lib/mysql-sql'

describe('quoteMysqlStringLiteral', () => {
  test('doubles single quotes', () => {
    expect(quoteMysqlStringLiteral("o'reilly")).toBe("'o''reilly'")
  })

  test('doubles backslashes so a trailing backslash cannot escape the quote', () => {
    expect(quoteMysqlStringLiteral("x\\' OR 1=1 -- ")).toBe(
      "'x\\\\'' OR 1=1 -- '",
    )
    expect(quoteMysqlStringLiteral('\\')).toBe("'\\\\'")
  })

  test('row literals use the same quoting', () => {
    expect(formatMysqlSqlLiteral('a\\b')).toBe("'a\\\\b'")
  })
})
