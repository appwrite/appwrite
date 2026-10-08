import { describe, expect, test } from 'bun:test'
import {
  buildMysqlAddColumnSql,
  formatMysqlColumnDefaultSql,
} from '@/lib/mysql-table-ddl'

describe('formatMysqlColumnDefaultSql', () => {
  test('quotes values in value mode', () => {
    expect(formatMysqlColumnDefaultSql('eldad', 'VARCHAR(255)', 'value')).toBe(
      "'eldad'",
    )
    expect(formatMysqlColumnDefaultSql('eldad', 'TEXT', 'value')).toBe(
      "('eldad')",
    )
    expect(formatMysqlColumnDefaultSql("o'reilly", 'VARCHAR(255)', 'value')).toBe(
      "'o''reilly'",
    )
  })

  test('passes expressions through and wraps TEXT/JSON', () => {
    expect(
      formatMysqlColumnDefaultSql('CURRENT_TIMESTAMP', 'DATETIME', 'expression'),
    ).toBe('CURRENT_TIMESTAMP')
    expect(formatMysqlColumnDefaultSql('NULL', 'TEXT', 'expression')).toBe(
      '(NULL)',
    )
    expect(
      formatMysqlColumnDefaultSql("CAST('{}' AS JSON)", 'JSON', 'expression'),
    ).toBe("(CAST('{}' AS JSON))")
  })
})

describe('buildMysqlAddColumnSql', () => {
  test('quotes TEXT values and leaves timestamp expressions', () => {
    expect(
      buildMysqlAddColumnSql('public.test', 'name', 'TEXT', {
        defaultValue: 'eldad',
        defaultKind: 'value',
      }),
    ).toContain("ADD COLUMN `name` TEXT DEFAULT ('eldad')")

    expect(
      buildMysqlAddColumnSql('public.test', 'created_at', 'DATETIME', {
        defaultValue: 'CURRENT_TIMESTAMP',
        defaultKind: 'expression',
      }),
    ).toContain('DEFAULT CURRENT_TIMESTAMP')
  })

  test('emits DEFAULT NULL when the null checkbox is selected', () => {
    expect(
      buildMysqlAddColumnSql('public.test', 'test3', 'TEXT', {
        defaultIsNull: true,
        unique: true,
      }),
    ).toContain('ADD COLUMN `test3` TEXT DEFAULT NULL')
  })
})
