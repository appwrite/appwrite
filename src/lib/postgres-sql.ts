import type { Models } from '@appwrite.io/console'
import { format } from 'sql-formatter'
import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'

const POSTGRES_SCHEMAS_SYSTEM_FILTER = `
  schema_name NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
  AND schema_name NOT LIKE 'pg_temp_%'
  AND schema_name NOT LIKE 'pg_toast_temp_%'
`.trim()

export const POSTGRES_SIDEBAR_LIST_PAGE_SIZE = 50

export function quotePostgresStringLiteral(value: string): string {
  return `'${value.replace(/'/g, "''")}'`
}

export function escapePostgresLikePattern(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/%/g, '\\%')
    .replace(/_/g, '\\_')
}

export type PostgresListSchemasOptions = {
  search?: string
  limit?: number
  offset?: number
}

function appendPostgresSqlLimitOffset(
  sql: string,
  limit?: number,
  offset?: number,
): string {
  if (limit == null || !Number.isFinite(limit)) return sql
  const safeLimit = Math.max(1, Math.floor(limit))
  const safeOffset =
    offset != null && Number.isFinite(offset)
      ? Math.max(0, Math.floor(offset))
      : 0
  return `${sql}\nLIMIT ${safeLimit} OFFSET ${safeOffset}`
}

export function buildPostgresListSchemasSql(
  options?: PostgresListSchemasOptions,
): string {
  const conditions = [POSTGRES_SCHEMAS_SYSTEM_FILTER]

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapePostgresLikePattern(search)}%`
    conditions.push(
      `schema_name ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  const base = `
SELECT schema_name
FROM information_schema.schemata
WHERE ${conditions.join('\n  AND ')}
ORDER BY schema_name
`.trim()

  return appendPostgresSqlLimitOffset(
    base,
    options?.limit,
    options?.offset,
  )
}

export function buildPostgresListSchemasCountSql(
  options?: Pick<PostgresListSchemasOptions, 'search'>,
): string {
  const conditions = [POSTGRES_SCHEMAS_SYSTEM_FILTER]

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapePostgresLikePattern(search)}%`
    conditions.push(
      `schema_name ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  return `
SELECT COUNT(*) AS total
FROM information_schema.schemata
WHERE ${conditions.join('\n  AND ')}
`.trim()
}

export const POSTGRES_LIST_SCHEMAS_SQL = buildPostgresListSchemasSql()

const POSTGRES_TABLES_SYSTEM_SCHEMA_FILTER = `
  table_schema NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
  AND table_schema NOT LIKE 'pg_temp_%'
  AND table_schema NOT LIKE 'pg_toast_temp_%'
`.trim()

export type PostgresListTablesOptions = {
  schema?: string
  search?: string
  limit?: number
  offset?: number
}

export function buildPostgresListTablesSql(
  options?: PostgresListTablesOptions,
): string {
  const conditions = [
    POSTGRES_TABLES_SYSTEM_SCHEMA_FILTER,
    `table_type IN ('BASE TABLE', 'VIEW')`,
  ]

  const schema = options?.schema?.trim()
  if (schema) {
    conditions.push(`table_schema = ${quotePostgresStringLiteral(schema)}`)
  }

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapePostgresLikePattern(search)}%`
    conditions.push(
      `table_name ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  const base = `
SELECT table_schema, table_name, table_type
FROM information_schema.tables
WHERE ${conditions.join('\n  AND ')}
ORDER BY table_schema, table_name
`.trim()

  return appendPostgresSqlLimitOffset(
    base,
    options?.limit,
    options?.offset,
  )
}

export function buildPostgresListTablesCountSql(
  options?: Pick<PostgresListTablesOptions, 'schema' | 'search'>,
): string {
  const conditions = [
    POSTGRES_TABLES_SYSTEM_SCHEMA_FILTER,
    `table_type IN ('BASE TABLE', 'VIEW')`,
  ]

  const schema = options?.schema?.trim()
  if (schema) {
    conditions.push(`table_schema = ${quotePostgresStringLiteral(schema)}`)
  }

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapePostgresLikePattern(search)}%`
    conditions.push(
      `table_name ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  return `
SELECT COUNT(*) AS total
FROM information_schema.tables
WHERE ${conditions.join('\n  AND ')}
`.trim()
}

export const POSTGRES_LIST_TABLES_SQL = buildPostgresListTablesSql()

export const POSTGRES_LIST_COLUMNS_SQL = `
SELECT table_schema, table_name, column_name, data_type, is_nullable, ordinal_position
FROM information_schema.columns
WHERE table_schema NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
  AND table_schema NOT LIKE 'pg_temp_%'
  AND table_schema NOT LIKE 'pg_toast_temp_%'
ORDER BY table_schema, table_name, ordinal_position
`.trim()

export type PostgresSchemaRow = {
  schema_name: string
}

export type PostgresTableRow = {
  table_schema: string
  table_name: string
  table_type: string
}

export type PostgresColumnRow = {
  table_schema: string
  table_name: string
  column_name: string
  data_type: string
  is_nullable: string
  ordinal_position: number | string
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

export function formatPostgresRowCount(count: number): string {
  const formatted = count.toLocaleString()
  return `${formatted} row${count === 1 ? '' : 's'}`
}

export function formatPostgresQueryDurationMs(ms: number): string {
  if (ms < 1) return '< 1 ms'
  if (ms < 1000) {
    if (ms < 10) {
      const rounded = Math.round(ms * 10) / 10
      return `${rounded} ms`
    }
    return `${Math.round(ms)} ms`
  }
  const seconds = ms / 1000
  if (seconds < 60) {
    return seconds < 10 ? `${seconds.toFixed(2)} s` : `${seconds.toFixed(1)} s`
  }
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  if (remainingSeconds < 0.05) return `${minutes} min`
  return `${minutes} min ${remainingSeconds.toFixed(0)} s`
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
