import { describe, expect, test } from 'bun:test'
import {
  buildPostgresAddColumnSql,
  formatPostgresColumnDefaultSql,
} from '@/lib/postgres-table-ddl'

describe('formatPostgresColumnDefaultSql', () => {
  test('quotes values in value mode', () => {
    expect(formatPostgresColumnDefaultSql('test1234', 'value')).toBe("'test1234'")
    expect(formatPostgresColumnDefaultSql("o'reilly", 'value')).toBe("'o''reilly'")
  })

  test('passes expressions through', () => {
    expect(formatPostgresColumnDefaultSql('now()', 'expression')).toBe('now()')
    expect(formatPostgresColumnDefaultSql('gen_random_uuid()', 'expression')).toBe(
      'gen_random_uuid()',
    )
    expect(formatPostgresColumnDefaultSql("'{}'::jsonb", 'expression')).toBe(
      "'{}'::jsonb",
    )
  })
})

describe('buildPostgresAddColumnSql', () => {
  test('quotes text values and leaves now() as an expression', () => {
    expect(
      buildPostgresAddColumnSql('public.test', 'name', 'text', {
        defaultValue: 'test1234',
        defaultKind: 'value',
      }),
    ).toContain(`ADD COLUMN "name" text DEFAULT 'test1234'`)

    expect(
      buildPostgresAddColumnSql('public.test', 'created_at', 'timestamptz', {
        defaultValue: 'now()',
        defaultKind: 'expression',
      }),
    ).toContain('DEFAULT now()')
  })

  test('emits DEFAULT NULL when the null checkbox is selected', () => {
    expect(
      buildPostgresAddColumnSql('public.test', 'name', 'text', {
        defaultIsNull: true,
        unique: true,
      }),
    ).toContain('ADD COLUMN "name" text UNIQUE DEFAULT NULL')
  })
})
