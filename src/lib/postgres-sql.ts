import type { Models } from '@appwrite.io/console'
import { format } from 'sql-formatter'
import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'

export const POSTGRES_LIST_SCHEMAS_SQL = `
SELECT schema_name
FROM information_schema.schemata
WHERE schema_name NOT IN ('pg_catalog', 'information_schema')
  AND schema_name NOT LIKE 'pg_temp_%'
ORDER BY schema_name
`.trim()

export const POSTGRES_LIST_TABLES_SQL = `
SELECT table_schema, table_name, table_type
FROM information_schema.tables
WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
  AND table_type IN ('BASE TABLE', 'VIEW')
ORDER BY table_schema, table_name
`.trim()

export type PostgresSchemaRow = {
  schema_name: string
}

export type PostgresTableRow = {
  table_schema: string
  table_name: string
  table_type: string
}

export function executionResultRows<T extends Record<string, unknown>>(
  execution: Models.DedicatedDatabaseExecution,
): T[] {
  const { rows } = execution
  if (Array.isArray(rows)) {
    return rows as T[]
  }
  if (rows && typeof rows === 'object') {
    return Object.values(rows as Record<string, T>)
  }
  return []
}

export function buildPostgresSelectSql(
  schema: string,
  table: string,
  limit: number,
  offset: number,
): string {
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `SELECT * FROM ${qualified} LIMIT ${limit} OFFSET ${offset}`
}

export function buildPostgresCountSql(schema: string, table: string): string {
  const qualified = `${quotePostgresIdentifier(schema)}.${quotePostgresIdentifier(table)}`
  return `SELECT COUNT(*) AS total FROM ${qualified}`
}

/** Pretty-print SQL for the Postgres SQL editor. Returns the original string on parse errors. */
export function formatPostgresSql(sql: string): string {
  const trimmed = sql.trim()
  if (!trimmed) return sql

  try {
    return format(trimmed, {
      language: 'postgresql',
      tabWidth: 2,
      keywordCase: 'upper',
    })
  } catch {
    return sql
  }
}
