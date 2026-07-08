import type { Models } from '@appwrite.io/console'
import { format } from 'sql-formatter'
import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'

const POSTGRES_NAMESPACE_SYSTEM_FILTER = `
  n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
  AND n.nspname NOT LIKE 'pg_temp_%'
  AND n.nspname NOT LIKE 'pg_toast_temp_%'
`.trim()

const POSTGRES_SCHEMAS_SYSTEM_FILTER = `
  nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
  AND nspname NOT LIKE 'pg_temp_%'
  AND nspname NOT LIKE 'pg_toast_temp_%'
`.trim()

export const POSTGRES_SIDEBAR_LIST_PAGE_SIZE = 50

export function quotePostgresStringLiteral(value: string): string {
  return `'${value.replace(/'/g, "''")}'`
}

/** Strip leading line and block comments for statement classification. */
export function stripLeadingPostgresSqlComments(sql: string): string {
  return peelLeadingPostgresSqlComments(sql).sqlWithoutLeadingComments
}

/** Split leading SQL comments from the executable statement. */
export function peelLeadingPostgresSqlComments(sql: string): {
  leadingComments: string
  sqlWithoutLeadingComments: string
} {
  const commentLines: string[] = []
  let remaining = sql.trimStart()

  while (remaining.length > 0) {
    if (remaining.startsWith('--')) {
      const newlineIndex = remaining.indexOf('\n')
      if (newlineIndex === -1) {
        commentLines.push(remaining)
        remaining = ''
        break
      }
      commentLines.push(remaining.slice(0, newlineIndex))
      remaining = remaining.slice(newlineIndex + 1).trimStart()
      continue
    }
    if (remaining.startsWith('/*')) {
      const endIndex = remaining.indexOf('*/')
      if (endIndex === -1) {
        commentLines.push(remaining)
        remaining = ''
        break
      }
      commentLines.push(remaining.slice(0, endIndex + 2))
      remaining = remaining.slice(endIndex + 2).trimStart()
      continue
    }
    break
  }

  return {
    leadingComments: commentLines.join('\n'),
    sqlWithoutLeadingComments: remaining.trim(),
  }
}

/** Prefix SQL with a short `--` comment for API tracing. */
export function prefixPostgresSqlComment(sql: string, comment: string): string {
  const trimmedSql = sql.trim()
  if (!trimmedSql) return trimmedSql
  const trimmedComment = comment.trim().replace(/\s+/g, ' ')
  if (!trimmedComment) return trimmedSql
  return `-- ${trimmedComment}\n${trimmedSql}`
}

function stripTrailingPostgresSqlSemicolon(sql: string): string {
  return sql.trim().replace(/;\s*$/, '')
}

/** Wrap multiple DDL statements in one DO block (API allows one command per request). */
export function buildPostgresSingleRequestDdlSql(
  statements: string[],
  traceComment: string,
): string {
  const normalized = statements
    .map((statement) => stripTrailingPostgresSqlSemicolon(statement))
    .filter(Boolean)
  if (normalized.length === 0) return ''
  if (normalized.length === 1) {
    return prefixPostgresSqlComment(normalized[0]!, traceComment)
  }
  const body = normalized.map((statement) => `${statement};`).join('\n  ')
  return prefixPostgresSqlComment(
    `DO $appwrite_ddl$\nBEGIN\n  ${body}\nEND\n$appwrite_ddl$`,
    traceComment,
  )
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
      `nspname ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  const base = `
SELECT nspname AS schema_name
FROM pg_catalog.pg_namespace
WHERE ${conditions.join('\n  AND ')}
ORDER BY nspname
`.trim()

  return prefixPostgresSqlComment(
    appendPostgresSqlLimitOffset(
      base,
      options?.limit,
      options?.offset,
    ),
    'List database schemas',
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
      `nspname ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  return prefixPostgresSqlComment(
    `
SELECT COUNT(*) AS total
FROM pg_catalog.pg_namespace
WHERE ${conditions.join('\n  AND ')}
`.trim(),
    'Count database schemas',
  )
}

const POSTGRES_TABLES_SYSTEM_SCHEMA_FILTER = POSTGRES_NAMESPACE_SYSTEM_FILTER

const POSTGRES_TABLE_RELKIND_FILTER = `c.relkind IN ('r', 'v')`

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
    POSTGRES_TABLE_RELKIND_FILTER,
  ]

  const schema = options?.schema?.trim()
  if (schema) {
    conditions.push(`n.nspname = ${quotePostgresStringLiteral(schema)}`)
  }

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapePostgresLikePattern(search)}%`
    conditions.push(
      `c.relname ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  const base = `
SELECT
  n.nspname AS table_schema,
  c.relname AS table_name,
  CASE c.relkind
    WHEN 'r' THEN 'BASE TABLE'
    WHEN 'v' THEN 'VIEW'
    ELSE c.relkind::text
  END AS table_type
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE ${conditions.join('\n  AND ')}
ORDER BY n.nspname, c.relname
`.trim()

  return prefixPostgresSqlComment(
    appendPostgresSqlLimitOffset(
      base,
      options?.limit,
      options?.offset,
    ),
    'List tables and views',
  )
}

export function buildPostgresListTablesCountSql(
  options?: Pick<PostgresListTablesOptions, 'schema' | 'search'>,
): string {
  const conditions = [
    POSTGRES_TABLES_SYSTEM_SCHEMA_FILTER,
    POSTGRES_TABLE_RELKIND_FILTER,
  ]

  const schema = options?.schema?.trim()
  if (schema) {
    conditions.push(`n.nspname = ${quotePostgresStringLiteral(schema)}`)
  }

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapePostgresLikePattern(search)}%`
    conditions.push(
      `c.relname ILIKE ${quotePostgresStringLiteral(pattern)} ESCAPE ${quotePostgresStringLiteral('\\')}`,
    )
  }

  return prefixPostgresSqlComment(
    `
SELECT COUNT(*) AS total
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE ${conditions.join('\n  AND ')}
`.trim(),
    'Count tables and views',
  )
}

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
  /** Single-column UNIQUE constraint (not part of a composite unique key). */
  is_unique: boolean | string
  primary_key_constraint: string | null
  unique_constraint: string | null
  column_comment: string | null
  check_constraints: string | null
  foreign_keys: string | null
}

function isPostgresTruthyFlag(value: unknown): boolean {
  if (value === true) return true
  const normalized = String(value ?? '')
    .trim()
    .toLowerCase()
  return normalized === 'true' || normalized === 't' || normalized === '1'
}

export function isPostgresPrimaryKeyColumn(
  column: Pick<PostgresTableColumnRow, 'is_primary_key'>,
): boolean {
  return isPostgresTruthyFlag(column.is_primary_key)
}

export function isPostgresUniqueColumn(
  column: Pick<PostgresTableColumnRow, 'is_unique'>,
): boolean {
  return isPostgresTruthyFlag(column.is_unique)
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

export type PostgresSchemaEnumRow = {
  enum_name: string
  enum_schema: string
  enum_values: unknown
  enum_comment: string | null
  used_in_schema: boolean | string
  values: string[]
}

export function sortPostgresSchemaEnums<T extends Pick<PostgresSchemaEnumRow, 'enum_name'>>(
  enums: T[],
): T[] {
  return [...enums].sort((a, b) => a.enum_name.localeCompare(b.enum_name))
}

export function isPostgresEnumUsedInSchema(
  row: Pick<PostgresSchemaEnumRow, 'used_in_schema'>,
): boolean {
  const value = row.used_in_schema
  return value === true || value === 'true' || value === 't'
}

export function buildPostgresSchemaEnumsSql(schema: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  return prefixPostgresSqlComment(
    `
SELECT
  t.typname AS enum_name,
  n.nspname AS enum_schema,
  COALESCE(
    json_agg(e.enumlabel ORDER BY e.enumsortorder),
    '[]'::json
  ) AS enum_values,
  obj_description(t.oid, 'pg_type') AS enum_comment,
  EXISTS (
    SELECT 1
    FROM information_schema.columns c
    WHERE c.table_schema = ${schemaLit}
      AND c.udt_name = t.typname
  ) AS used_in_schema
FROM pg_type t
JOIN pg_namespace n ON n.oid = t.typnamespace
LEFT JOIN pg_enum e ON e.enumtypid = t.oid
WHERE n.nspname = ${schemaLit}
  AND t.typtype = 'e'
GROUP BY t.typname, n.nspname, t.oid
ORDER BY t.typname
`.trim(),
    'Load schema enums',
  )
}

export function buildPostgresTableColumnsSql(schema: string, table: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
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
  CASE WHEN uq.column_name IS NOT NULL THEN true ELSE false END AS is_unique,
  pk.constraint_name AS primary_key_constraint,
  uq.constraint_name AS unique_constraint,
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
  SELECT kcu.column_name, tc.constraint_name
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
  SELECT kcu.column_name, tc.constraint_name
  FROM information_schema.table_constraints tc
  JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
    AND tc.table_schema = kcu.table_schema
    AND tc.table_name = kcu.table_name
  WHERE tc.constraint_type = 'UNIQUE'
    AND tc.table_schema = ${schemaLit}
    AND tc.table_name = ${tableLit}
    AND (
      SELECT COUNT(*)::int
      FROM information_schema.key_column_usage kcu2
      WHERE kcu2.constraint_schema = tc.constraint_schema
        AND kcu2.constraint_name = tc.constraint_name
        AND kcu2.table_schema = tc.table_schema
        AND kcu2.table_name = tc.table_name
    ) = 1
) uq ON c.column_name = uq.column_name
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
`.trim(),
    'List table columns',
  )
}

/** Lighter column metadata for row browsing/editing (pg_catalog only, no information_schema). */
export function buildPostgresTableColumnsForRowsSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
WITH rel AS (
  SELECT c.oid AS rel_oid, c.relkind
  FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = ${schemaLit}
    AND c.relname = ${tableLit}
)
SELECT
  a.attname AS column_name,
  format_type(a.atttypid, a.atttypmod) AS data_type,
  t.typname AS udt_name,
  CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS is_nullable,
  pg_get_expr(d.adbin, d.adrelid) AS column_default,
  CASE WHEN a.attidentity IN ('a', 'd') THEN 'YES' ELSE 'NO' END AS is_identity,
  CASE a.attidentity
    WHEN 'a' THEN 'ALWAYS'
    WHEN 'd' THEN 'BY DEFAULT'
    ELSE NULL
  END AS identity_generation,
  pg_get_serial_sequence(
    quote_ident(${schemaLit}) || '.' || quote_ident(${tableLit}),
    a.attname
  ) AS serial_sequence,
  CASE
    WHEN t.typname IN ('varchar', 'bpchar', 'bit', 'varbit') AND a.atttypmod > 0
      THEN a.atttypmod - 4
    ELSE NULL
  END AS character_maximum_length,
  CASE
    WHEN t.typname = 'numeric' AND a.atttypmod > 0
      THEN ((a.atttypmod - 4) >> 16) & 65535
    ELSE NULL
  END AS numeric_precision,
  CASE
    WHEN t.typname = 'numeric' AND a.atttypmod > 0
      THEN (a.atttypmod - 4) & 65535
    ELSE NULL
  END AS numeric_scale,
  CASE
    WHEN t.typname IN ('time', 'timetz', 'timestamp', 'timestamptz', 'interval')
      AND a.atttypmod > 0
      THEN a.atttypmod
    ELSE NULL
  END AS datetime_precision,
  a.attnum AS ordinal_position,
  CASE WHEN pk.contype = 'p' THEN true ELSE false END AS is_primary_key,
  CASE
    WHEN uq.contype = 'u' AND cardinality(uq.conkey) = 1 THEN true
    ELSE false
  END AS is_unique,
  pk.conname AS primary_key_constraint,
  CASE
    WHEN uq.contype = 'u' AND cardinality(uq.conkey) = 1 THEN uq.conname
    ELSE NULL
  END AS unique_constraint,
  NULL::text AS column_comment,
  NULL::text AS check_constraints,
  NULL::text AS foreign_keys,
  rel.relkind AS rel_kind
FROM rel
LEFT JOIN pg_catalog.pg_attribute a
  ON a.attrelid = rel.rel_oid
 AND a.attnum > 0
 AND NOT a.attisdropped
LEFT JOIN pg_catalog.pg_type t
  ON t.oid = a.atttypid
LEFT JOIN pg_catalog.pg_attrdef d
  ON d.adrelid = a.attrelid
 AND d.adnum = a.attnum
LEFT JOIN pg_catalog.pg_constraint pk
  ON pk.conrelid = rel.rel_oid
 AND pk.contype = 'p'
 AND a.attnum = ANY (pk.conkey)
LEFT JOIN pg_catalog.pg_constraint uq
  ON uq.conrelid = rel.rel_oid
 AND uq.contype = 'u'
 AND a.attnum = ANY (uq.conkey)
 AND cardinality(uq.conkey) = 1
WHERE a.attname IS NOT NULL
   OR NOT EXISTS (
     SELECT 1
     FROM pg_catalog.pg_attribute a2
     WHERE a2.attrelid = rel.rel_oid
       AND a2.attnum > 0
       AND NOT a2.attisdropped
   )
ORDER BY CASE WHEN pk.contype = 'p' THEN 0 ELSE 1 END, a.attnum
`.trim(),
    'Load row columns',
  )
}

/** Slim per-table column list for SQL editor autocomplete (pg_catalog only). */
export function buildPostgresTableAutocompleteColumnsSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
SELECT
  n.nspname AS table_schema,
  c.relname AS table_name,
  a.attname AS column_name,
  format_type(a.atttypid, a.atttypmod) AS data_type,
  CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS is_nullable,
  a.attnum AS ordinal_position
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
JOIN pg_catalog.pg_attribute a
  ON a.attrelid = c.oid
 AND a.attnum > 0
 AND NOT a.attisdropped
WHERE n.nspname = ${schemaLit}
  AND c.relname = ${tableLit}
ORDER BY a.attnum
`.trim(),
    'List autocomplete columns',
  )
}

/** Base tables and materialized views expose ctid; ordinary views do not. */
export function postgresRelationSupportsRowCtid(relKind: string | null | undefined): boolean {
  return relKind === 'r' || relKind === 'm'
}

export function buildPostgresTableIndexesSql(schema: string, table: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
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
`.trim(),
    'List table indexes',
  )
}

export function buildPostgresTableInfoSql(schema: string, table: string): string {
  const schemaLit = quotePostgresStringLiteral(schema)
  const tableLit = quotePostgresStringLiteral(table)
  return prefixPostgresSqlComment(
    `
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
`.trim(),
    'Load table info',
  )
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

/** Default SELECT for the SQL editor (no API trace comment). */
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
  return prefixPostgresSqlComment(
    `SELECT COUNT(*) AS total FROM ${qualified}`,
    'Count table rows',
  )
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
