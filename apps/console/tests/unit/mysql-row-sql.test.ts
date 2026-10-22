import { describe, expect, test } from 'bun:test'
import {
  buildMysqlDeleteRowSql,
  buildMysqlUpdateRowSql,
} from '@/lib/mysql-row-sql'
import { quoteMysqlStringLiteral } from '@/lib/mysql-sql'

describe('quoteMysqlStringLiteral', () => {
  test('doubles single quotes', () => {
    expect(quoteMysqlStringLiteral("o'reilly")).toBe("'o''reilly'")
  })

  test('escapes backslashes so they cannot escape the closing quote', () => {
    expect(quoteMysqlStringLiteral("a\\'b")).toBe("'a\\\\''b'")
    expect(quoteMysqlStringLiteral('ends with \\')).toBe("'ends with \\\\'")
  })

  test('escapes NUL characters', () => {
    expect(quoteMysqlStringLiteral('a\0b')).toBe("'a\\0b'")
  })
})

describe('row statements', () => {
  const identity = { primaryKeyValues: { id: 'x\\' } }

  test('update targets a single row', () => {
    expect(buildMysqlUpdateRowSql('app.posts', identity, { title: 't' })).toBe(
      "-- Update table row\nUPDATE `app`.`posts`\nSET `title` = 't'\nWHERE `id` = 'x\\\\'\nLIMIT 1",
    )
  })

  test('delete targets a single row', () => {
    expect(buildMysqlDeleteRowSql('app.posts', identity)).toBe(
      "-- Delete table row\nDELETE FROM `app`.`posts`\nWHERE `id` = 'x\\\\'\nLIMIT 1",
    )
  })
})
