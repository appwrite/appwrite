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

export type PostgresTableColumnRow = {
  column_name: string
  data_type: string
  udt_name: string
  is_nullable: string
  column_default: string | null
  is_identity: string | null
  identity_generation: string | null
  serial_sequence: string | null
  character_maximum_length: number | string | null
  numeric_precision: number | string | null
  numeric_scale: number | string | null
  datetime_precision: number | string | null
  ordinal_position: number | string
  is_primary_key: boolean | string
  column_comment: string | null
  check_constraints: string | null
  foreign_keys: string | null
}

export function isPostgresPrimaryKeyColumn(
  column: Pick<PostgresTableColumnRow, 'is_primary_key'>,
): boolean {
  return column.is_primary_key === true || column.is_primary_key === 'true'
}

export function sortPostgresTableColumns<T extends PostgresTableColumnRow>(
  columns: T[],
): T[] {
  return [...columns].sort((a, b) => {
    const aPrimary = isPostgresPrimaryKeyColumn(a)
    const bPrimary = isPostgresPrimaryKeyColumn(b)
    if (aPrimary !== bPrimary) return aPrimary ? -1 : 1

    return Number(a.ordinal_position) - Number(b.ordinal_position)
  })
}

export type PostgresTableIndexRow = {
  index_name: string
  index_definition: string
  is_unique: boolean | string
  is_primary: boolean | string
  index_algorithm: string | null
  index_condition: string | null
  index_include: string | null
  index_comment: string | null
}

export function isPostgresPrimaryIndex(
  index: Pick<PostgresTableIndexRow, 'is_primary'>,
): boolean {
  const value = index.is_primary
  return value === true || value === 'true' || value === 't'
}

export function sortPostgresTableIndexes<T extends PostgresTableIndexRow>(
  indexes: T[],
): T[] {
  return [...indexes].sort((a, b) => {
    const aPrimary = isPostgresPrimaryIndex(a)
    const bPrimary = isPostgresPrimaryIndex(b)
    if (aPrimary !== bPrimary) return aPrimary ? -1 : 1

    return a.index_name.localeCompare(b.index_name)
  })
}

export type PostgresTableInfoRow = {
  table_schema: string
  table_name: string
  table_type: string
  total_bytes: number | string | null
  table_comment: string | null
  estimated_rows: number | string | null
}

export function buildPostgresTableColumnsSql(schema: string, table: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return `
SELECT
  c.column_name,
  c.data_type,
  c.udt_name,
  c.is_nullable,
  COALESCE(pg_get_expr(def.adbin, def.adrelid), c.column_default) AS column_default,
  CASE WHEN attr.attidentity IN ('a', 'd') THEN 'YES' ELSE 'NO' END AS is_identity,
  CASE attr.attidentity
    WHEN 'a' THEN 'ALWAYS'
    WHEN 'd' THEN 'BY DEFAULT'
    ELSE NULL
  END AS identity_generation,
  pg_get_serial_sequence(
    quote_ident(c.table_schema) || '.' || quote_ident(c.table_name),
    c.column_name
  ) AS serial_sequence,
  c.character_maximum_length,
  c.numeric_precision,
  c.numeric_scale,
  c.datetime_precision,
  c.ordinal_position,
  CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END AS is_primary_key,
  pg_catalog.col_description(pgc.oid, c.ordinal_position::int) AS column_comment,
  checks.check_constraints,
  fkeys.foreign_keys
FROM information_schema.columns c
JOIN pg_catalog.pg_class pgc
  ON pgc.relname = c.table_name
JOIN pg_catalog.pg_namespace n
  ON n.oid = pgc.relnamespace
  AND n.nspname = c.table_schema
LEFT JOIN pg_attribute attr
  ON attr.attrelid = pgc.oid
  AND attr.attname = c.column_name
  AND attr.attnum > 0
  AND NOT attr.attisdropped
LEFT JOIN pg_attrdef def
  ON def.adrelid = attr.attrelid
  AND def.adnum = attr.attnum
LEFT JOIN (
  SELECT kcu.column_name
  FROM information_schema.table_constraints tc
  JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
    AND tc.table_schema = kcu.table_schema
    AND tc.table_name = kcu.table_name
  WHERE tc.constraint_type = 'PRIMARY KEY'
    AND tc.table_schema = ${schemaLit}
    AND tc.table_name = ${tableLit}
) pk ON c.column_name = pk.column_name
LEFT JOIN (
  SELECT
    ccu.column_name,
    string_agg(
      tc.constraint_name || '::' || cc.check_clause,
      E'\\n'
      ORDER BY tc.constraint_name
    ) AS check_constraints
  FROM information_schema.table_constraints tc
  JOIN information_schema.check_constraints cc
    ON tc.constraint_name = cc.constraint_name
    AND tc.constraint_schema = cc.constraint_schema
  JOIN information_schema.constraint_column_usage ccu
    ON ccu.constraint_name = tc.constraint_name
    AND ccu.constraint_schema = tc.constraint_schema
    AND ccu.table_schema = tc.table_schema
    AND ccu.table_name = tc.table_name
  WHERE tc.table_schema = ${schemaLit}
    AND tc.table_name = ${tableLit}
    AND tc.constraint_type = 'CHECK'
  GROUP BY ccu.column_name
) checks ON checks.column_name = c.column_name
LEFT JOIN (
  SELECT
    src.column_name,
    string_agg(
      tc.constraint_name || '::' || ref.table_schema || '.' || ref.table_name || '(' || ref.column_name || ')',
      E'\\n'
      ORDER BY tc.constraint_name, src.ordinal_position
    ) AS foreign_keys
  FROM information_schema.table_constraints tc
  JOIN information_schema.key_column_usage src
    ON src.constraint_name = tc.constraint_name
    AND src.table_schema = tc.table_schema
    AND src.table_name = tc.table_name
  JOIN information_schema.referential_constraints rc
    ON rc.constraint_name = tc.constraint_name
    AND rc.constraint_schema = tc.constraint_schema
  JOIN information_schema.key_column_usage ref
    ON ref.constraint_name = rc.unique_constraint_name
    AND ref.constraint_schema = rc.unique_constraint_schema
    AND ref.ordinal_position = src.ordinal_position
  WHERE tc.table_schema = ${schemaLit}
    AND tc.table_name = ${tableLit}
    AND tc.constraint_type = 'FOREIGN KEY'
  GROUP BY src.column_name
) fkeys ON fkeys.column_name = c.column_name
WHERE c.table_schema = ${schemaLit}
  AND c.table_name = ${tableLit}
ORDER BY CASE WHEN pk.column_name IS NOT NULL THEN 0 ELSE 1 END, c.ordinal_position
`.trim()
}

export function buildPostgresTableIndexesSql(schema: string, table: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return `
SELECT
  i.relname AS index_name,
  pg_get_indexdef(i.oid) AS index_definition,
  ix.indisunique AS is_unique,
  ix.indisprimary AS is_primary,
  am.amname AS index_algorithm,
  pg_get_expr(ix.indpred, ix.indrelid) AS index_condition,
  pg_catalog.obj_description(i.oid, 'pg_class') AS index_comment,
  (
    SELECT string_agg(a.attname, ', ' ORDER BY u.ord)
    FROM unnest(ix.indkey) WITH ORDINALITY AS u(attnum, ord)
    JOIN pg_attribute a
      ON a.attrelid = t.oid
      AND a.attnum = u.attnum
      AND NOT a.attisdropped
    WHERE u.ord > ix.indnkeyatts
  ) AS index_include
FROM pg_class t
JOIN pg_namespace n ON n.oid = t.relnamespace
JOIN pg_index ix ON ix.indrelid = t.oid
JOIN pg_class i ON i.oid = ix.indexrelid
JOIN pg_am am ON am.oid = i.relam
WHERE n.nspname = ${schemaLit}
  AND t.relname = ${tableLit}
ORDER BY ix.indisprimary DESC, i.relname
`.trim()
}

export function buildPostgresTableInfoSql(schema: string, table: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return `
SELECT
  n.nspname AS table_schema,
  c.relname AS table_name,
  CASE c.relkind
    WHEN 'r' THEN 'BASE TABLE'
    WHEN 'v' THEN 'VIEW'
    WHEN 'm' THEN 'MATERIALIZED VIEW'
    ELSE c.relkind::text
  END AS table_type,
  pg_total_relation_size(c.oid) AS total_bytes,
  obj_description(c.oid) AS table_comment,
  c.reltuples::bigint AS estimated_rows
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = ${schemaLit}
  AND c.relname = ${tableLit}
`.trim()
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
