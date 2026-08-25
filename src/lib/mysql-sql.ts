import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { format } from 'sql-formatter'
import { quoteMysqlIdentifier } from '@/lib/mysql-database-routes'

const MYSQL_SYSTEM_SCHEMAS = `('mysql', 'information_schema', 'performance_schema', 'sys')`

const MYSQL_TABLES_SYSTEM_SCHEMA_FILTER = `
  TABLE_SCHEMA NOT IN ${MYSQL_SYSTEM_SCHEMAS}
`.trim()

export const MYSQL_SIDEBAR_LIST_PAGE_SIZE = 50

export function quoteMysqlStringLiteral(value: string): string {
  return `'${value.replace(/'/g, "''")}'`
}

/** Strip leading line and block comments for statement classification. */
export function stripLeadingMysqlSqlComments(sql: string): string {
  return peelLeadingMysqlSqlComments(sql).sqlWithoutLeadingComments
}

/** Split leading SQL comments from the executable statement. */
export function peelLeadingMysqlSqlComments(sql: string): {
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
export function prefixMysqlSqlComment(sql: string, comment: string): string {
  const trimmedSql = sql.trim()
  if (!trimmedSql) return trimmedSql
  const trimmedComment = comment.trim().replace(/\s+/g, ' ')
  if (!trimmedComment) return trimmedSql
  return `-- ${trimmedComment}\n${trimmedSql}`
}

function stripTrailingMysqlSqlSemicolon(sql: string): string {
  return sql.trim().replace(/;\s*$/, '')
}

/**
 * Combine multiple DDL statements for one API request.
 * MySQL has no DO $$ blocks; join with semicolons when more than one statement.
 */
export function buildMysqlSingleRequestDdlSql(
  statements: string[],
  traceComment: string,
): string {
  const normalized = statements
    .map((statement) => stripTrailingMysqlSqlSemicolon(statement))
    .filter(Boolean)
  if (normalized.length === 0) return ''
  if (normalized.length === 1) {
    return prefixMysqlSqlComment(normalized[0]!, traceComment)
  }
  return prefixMysqlSqlComment(normalized.join(';\n'), traceComment)
}

export function escapeMysqlLikePattern(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/%/g, '\\%')
    .replace(/_/g, '\\_')
}

export type MysqlListSchemasOptions = {
  search?: string
  limit?: number
  offset?: number
}

function appendMysqlSqlLimitOffset(
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

function mysqlLikeCondition(column: string, pattern: string): string {
  return `LOWER(${column}) LIKE LOWER(${quoteMysqlStringLiteral(pattern)}) ESCAPE ${quoteMysqlStringLiteral('\\')}`
}

export function buildMysqlListSchemasSql(
  options?: MysqlListSchemasOptions,
): string {
  const conditions: string[] = []

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapeMysqlLikePattern(search)}%`
    conditions.push(mysqlLikeCondition('SCHEMA_NAME', pattern))
  }

  const whereClause =
    conditions.length > 0 ? `WHERE ${conditions.join('\n  AND ')}` : ''

  // CONVERT to utf8mb4: cloud SQL API returns SCHEMA_NAME as VARBINARY byte
  // arrays otherwise, which the console must decode before the schema picker
  // can render.
  const base = `
SELECT CONVERT(SCHEMA_NAME USING utf8mb4) AS schema_name
FROM information_schema.SCHEMATA
${whereClause}
ORDER BY SCHEMA_NAME
`.trim()

  return prefixMysqlSqlComment(
    appendMysqlSqlLimitOffset(
      base,
      options?.limit,
      options?.offset,
    ),
    'List database schemas',
  )
}

export function buildMysqlListSchemasCountSql(
  options?: Pick<MysqlListSchemasOptions, 'search'>,
): string {
  const conditions: string[] = []

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapeMysqlLikePattern(search)}%`
    conditions.push(mysqlLikeCondition('SCHEMA_NAME', pattern))
  }

  const whereClause =
    conditions.length > 0 ? `WHERE ${conditions.join('\n  AND ')}` : ''

  return prefixMysqlSqlComment(
    `
SELECT COUNT(*) AS total
FROM information_schema.SCHEMATA
${whereClause}
`.trim(),
    'Count database schemas',
  )
}

export type MysqlListTablesOptions = {
  schema?: string
  search?: string
  limit?: number
  offset?: number
}

export function buildMysqlListTablesSql(
  options?: MysqlListTablesOptions,
): string {
  const conditions = [`TABLE_TYPE IN ('BASE TABLE', 'VIEW')`]

  const schema = options?.schema?.trim()
  if (schema) {
    // When a schema is selected (including system schemas), list its tables.
    conditions.push(`TABLE_SCHEMA = ${quoteMysqlStringLiteral(schema)}`)
  } else {
    // Cross-schema browse: hide internal MySQL schemas by default.
    conditions.push(MYSQL_TABLES_SYSTEM_SCHEMA_FILTER)
  }

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapeMysqlLikePattern(search)}%`
    conditions.push(mysqlLikeCondition('TABLE_NAME', pattern))
  }

  const base = `
SELECT
  TABLE_SCHEMA AS table_schema,
  TABLE_NAME AS table_name,
  TABLE_TYPE AS table_type
FROM information_schema.TABLES
WHERE ${conditions.join('\n  AND ')}
ORDER BY TABLE_SCHEMA, TABLE_NAME
`.trim()

  return prefixMysqlSqlComment(
    appendMysqlSqlLimitOffset(
      base,
      options?.limit,
      options?.offset,
    ),
    'List tables and views',
  )
}

export function buildMysqlListTablesCountSql(
  options?: Pick<MysqlListTablesOptions, 'schema' | 'search'>,
): string {
  const conditions = [`TABLE_TYPE IN ('BASE TABLE', 'VIEW')`]

  const schema = options?.schema?.trim()
  if (schema) {
    conditions.push(`TABLE_SCHEMA = ${quoteMysqlStringLiteral(schema)}`)
  } else {
    conditions.push(MYSQL_TABLES_SYSTEM_SCHEMA_FILTER)
  }

  const search = options?.search?.trim()
  if (search) {
    const pattern = `%${escapeMysqlLikePattern(search)}%`
    conditions.push(mysqlLikeCondition('TABLE_NAME', pattern))
  }

  return prefixMysqlSqlComment(
    `
SELECT COUNT(*) AS total
FROM information_schema.TABLES
WHERE ${conditions.join('\n  AND ')}
`.trim(),
    'Count tables and views',
  )
}

export type MysqlSchemaRow = {
  schema_name: string
}

export type MysqlTableRow = {
  table_schema: string
  table_name: string
  table_type: string
}

export type MysqlColumnRow = {
  table_schema: string
  table_name: string
  column_name: string
  data_type: string
  is_nullable: string
  ordinal_position: number | string
}

export type MysqlTableColumnRow = {
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

function isMysqlTruthyFlag(value: unknown): boolean {
  if (value === true) return true
  const normalized = String(value ?? '')
    .trim()
    .toLowerCase()
  return normalized === 'true' || normalized === 't' || normalized === '1'
}

export function isMysqlPrimaryKeyColumn(
  column: Pick<MysqlTableColumnRow, 'is_primary_key'>,
): boolean {
  return isMysqlTruthyFlag(column.is_primary_key)
}

export function isMysqlUniqueColumn(
  column: Pick<MysqlTableColumnRow, 'is_unique'>,
): boolean {
  return isMysqlTruthyFlag(column.is_unique)
}

export function sortMysqlTableColumns<T extends MysqlTableColumnRow>(
  columns: T[],
): T[] {
  return [...columns].sort((a, b) => {
    const aPrimary = isMysqlPrimaryKeyColumn(a)
    const bPrimary = isMysqlPrimaryKeyColumn(b)
    if (aPrimary !== bPrimary) return aPrimary ? -1 : 1

    return Number(a.ordinal_position) - Number(b.ordinal_position)
  })
}

export type MysqlTableIndexRow = {
  index_name: string
  index_definition: string
  is_unique: boolean | string
  is_primary: boolean | string
  index_algorithm: string | null
  index_condition: string | null
  index_include: string | null
  index_comment: string | null
}

export function isMysqlPrimaryIndex(
  index: Pick<MysqlTableIndexRow, 'is_primary'>,
): boolean {
  const value = index.is_primary
  return value === true || value === 'true' || value === 't' || value === 1 || value === '1'
}

export function sortMysqlTableIndexes<T extends MysqlTableIndexRow>(
  indexes: T[],
): T[] {
  return [...indexes].sort((a, b) => {
    const aPrimary = isMysqlPrimaryIndex(a)
    const bPrimary = isMysqlPrimaryIndex(b)
    if (aPrimary !== bPrimary) return aPrimary ? -1 : 1

    return a.index_name.localeCompare(b.index_name)
  })
}

export type MysqlTableInfoRow = {
  table_schema: string
  table_name: string
  table_type: string
  total_bytes: number | string | null
  table_comment: string | null
  estimated_rows: number | string | null
}

export function buildMysqlTableColumnsSql(schema: string, table: string): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  return prefixMysqlSqlComment(
    `
SELECT
  c.COLUMN_NAME AS column_name,
  c.DATA_TYPE AS data_type,
  COALESCE(c.COLUMN_TYPE, c.DATA_TYPE) AS udt_name,
  c.IS_NULLABLE AS is_nullable,
  c.COLUMN_DEFAULT AS column_default,
  CASE
    WHEN c.EXTRA LIKE '%auto_increment%' THEN 'YES'
    ELSE 'NO'
  END AS is_identity,
  CASE
    WHEN c.EXTRA LIKE '%auto_increment%' THEN 'BY DEFAULT'
    ELSE NULL
  END AS identity_generation,
  NULL AS serial_sequence,
  c.CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
  c.NUMERIC_PRECISION AS numeric_precision,
  c.NUMERIC_SCALE AS numeric_scale,
  c.DATETIME_PRECISION AS datetime_precision,
  c.ORDINAL_POSITION AS ordinal_position,
  CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN TRUE ELSE FALSE END AS is_primary_key,
  CASE WHEN uq.COLUMN_NAME IS NOT NULL THEN TRUE ELSE FALSE END AS is_unique,
  pk.CONSTRAINT_NAME AS primary_key_constraint,
  uq.CONSTRAINT_NAME AS unique_constraint,
  NULLIF(c.COLUMN_COMMENT, '') AS column_comment,
  NULL AS check_constraints,
  fkeys.foreign_keys
FROM information_schema.COLUMNS c
LEFT JOIN (
  SELECT kcu.COLUMN_NAME, tc.CONSTRAINT_NAME
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
  WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
    AND tc.TABLE_SCHEMA = ${schemaLit}
    AND tc.TABLE_NAME = ${tableLit}
) pk ON c.COLUMN_NAME = pk.COLUMN_NAME
LEFT JOIN (
  SELECT kcu.COLUMN_NAME, tc.CONSTRAINT_NAME
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
  WHERE tc.CONSTRAINT_TYPE = 'UNIQUE'
    AND tc.TABLE_SCHEMA = ${schemaLit}
    AND tc.TABLE_NAME = ${tableLit}
    AND (
      SELECT COUNT(*)
      FROM information_schema.KEY_COLUMN_USAGE kcu2
      WHERE kcu2.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
        AND kcu2.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
        AND kcu2.TABLE_SCHEMA = tc.TABLE_SCHEMA
        AND kcu2.TABLE_NAME = tc.TABLE_NAME
    ) = 1
) uq ON c.COLUMN_NAME = uq.COLUMN_NAME
LEFT JOIN (
  SELECT
    src.COLUMN_NAME AS column_name,
    GROUP_CONCAT(
      CONCAT(
        tc.CONSTRAINT_NAME, '::',
        src.REFERENCED_TABLE_SCHEMA, '.',
        src.REFERENCED_TABLE_NAME, '(',
        src.REFERENCED_COLUMN_NAME, ')'
      )
      ORDER BY tc.CONSTRAINT_NAME, src.ORDINAL_POSITION
      SEPARATOR '\\n'
    ) AS foreign_keys
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE src
    ON src.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
    AND src.TABLE_SCHEMA = tc.TABLE_SCHEMA
    AND src.TABLE_NAME = tc.TABLE_NAME
  WHERE tc.TABLE_SCHEMA = ${schemaLit}
    AND tc.TABLE_NAME = ${tableLit}
    AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND src.REFERENCED_TABLE_NAME IS NOT NULL
  GROUP BY src.COLUMN_NAME
) fkeys ON fkeys.column_name = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = ${schemaLit}
  AND c.TABLE_NAME = ${tableLit}
ORDER BY CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 0 ELSE 1 END, c.ORDINAL_POSITION
`.trim(),
    'List table columns',
  )
}

/** Lighter column metadata for row browsing/editing. */
export function buildMysqlTableColumnsForRowsSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  return prefixMysqlSqlComment(
    `
SELECT
  c.COLUMN_NAME AS column_name,
  c.DATA_TYPE AS data_type,
  COALESCE(c.COLUMN_TYPE, c.DATA_TYPE) AS udt_name,
  c.IS_NULLABLE AS is_nullable,
  c.COLUMN_DEFAULT AS column_default,
  CASE
    WHEN c.EXTRA LIKE '%auto_increment%' THEN 'YES'
    ELSE 'NO'
  END AS is_identity,
  CASE
    WHEN c.EXTRA LIKE '%auto_increment%' THEN 'BY DEFAULT'
    ELSE NULL
  END AS identity_generation,
  NULL AS serial_sequence,
  c.CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
  c.NUMERIC_PRECISION AS numeric_precision,
  c.NUMERIC_SCALE AS numeric_scale,
  c.DATETIME_PRECISION AS datetime_precision,
  c.ORDINAL_POSITION AS ordinal_position,
  CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN TRUE ELSE FALSE END AS is_primary_key,
  CASE WHEN uq.COLUMN_NAME IS NOT NULL THEN TRUE ELSE FALSE END AS is_unique,
  pk.CONSTRAINT_NAME AS primary_key_constraint,
  uq.CONSTRAINT_NAME AS unique_constraint,
  NULL AS column_comment,
  NULL AS check_constraints,
  NULL AS foreign_keys,
  CASE t.TABLE_TYPE
    WHEN 'BASE TABLE' THEN 'r'
    WHEN 'VIEW' THEN 'v'
    ELSE t.TABLE_TYPE
  END AS rel_kind
FROM information_schema.COLUMNS c
LEFT JOIN information_schema.TABLES t
  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
  AND t.TABLE_NAME = c.TABLE_NAME
LEFT JOIN (
  SELECT kcu.COLUMN_NAME, tc.CONSTRAINT_NAME
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
  WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
    AND tc.TABLE_SCHEMA = ${schemaLit}
    AND tc.TABLE_NAME = ${tableLit}
) pk ON c.COLUMN_NAME = pk.COLUMN_NAME
LEFT JOIN (
  SELECT kcu.COLUMN_NAME, tc.CONSTRAINT_NAME
  FROM information_schema.TABLE_CONSTRAINTS tc
  JOIN information_schema.KEY_COLUMN_USAGE kcu
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
    AND tc.TABLE_NAME = kcu.TABLE_NAME
  WHERE tc.CONSTRAINT_TYPE = 'UNIQUE'
    AND tc.TABLE_SCHEMA = ${schemaLit}
    AND tc.TABLE_NAME = ${tableLit}
    AND (
      SELECT COUNT(*)
      FROM information_schema.KEY_COLUMN_USAGE kcu2
      WHERE kcu2.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
        AND kcu2.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
        AND kcu2.TABLE_SCHEMA = tc.TABLE_SCHEMA
        AND kcu2.TABLE_NAME = tc.TABLE_NAME
    ) = 1
) uq ON c.COLUMN_NAME = uq.COLUMN_NAME
WHERE c.TABLE_SCHEMA = ${schemaLit}
  AND c.TABLE_NAME = ${tableLit}
ORDER BY CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 0 ELSE 1 END, c.ORDINAL_POSITION
`.trim(),
    'Load row columns',
  )
}

/** Slim per-table column list for SQL editor autocomplete. */
export function buildMysqlTableAutocompleteColumnsSql(
  schema: string,
  table: string,
): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  return prefixMysqlSqlComment(
    `
SELECT
  TABLE_SCHEMA AS table_schema,
  TABLE_NAME AS table_name,
  COLUMN_NAME AS column_name,
  DATA_TYPE AS data_type,
  IS_NULLABLE AS is_nullable,
  ORDINAL_POSITION AS ordinal_position
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = ${schemaLit}
  AND TABLE_NAME = ${tableLit}
ORDER BY ORDINAL_POSITION
`.trim(),
    'List autocomplete columns',
  )
}

/** MySQL has no ctid; always false. */
export function mysqlRelationSupportsRowCtid(
  _relKind?: string | null,
): boolean {
  return false
}

export function buildMysqlTableIndexesSql(schema: string, table: string): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  return prefixMysqlSqlComment(
    `
SELECT
  s.INDEX_NAME AS index_name,
  CONCAT(
    CASE WHEN s.NON_UNIQUE = 0 THEN 'UNIQUE ' ELSE '' END,
    'INDEX ', s.INDEX_NAME, ' ON ',
    s.TABLE_SCHEMA, '.', s.TABLE_NAME, ' (',
    GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX SEPARATOR ', '),
    ')',
    CASE WHEN s.INDEX_TYPE IS NOT NULL THEN CONCAT(' USING ', s.INDEX_TYPE) ELSE '' END
  ) AS index_definition,
  CASE WHEN s.NON_UNIQUE = 0 THEN TRUE ELSE FALSE END AS is_unique,
  CASE WHEN s.INDEX_NAME = 'PRIMARY' THEN TRUE ELSE FALSE END AS is_primary,
  LOWER(s.INDEX_TYPE) AS index_algorithm,
  NULL AS index_condition,
  NULL AS index_include,
  NULLIF(s.INDEX_COMMENT, '') AS index_comment
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA = ${schemaLit}
  AND s.TABLE_NAME = ${tableLit}
GROUP BY
  s.INDEX_NAME,
  s.NON_UNIQUE,
  s.INDEX_TYPE,
  s.INDEX_COMMENT,
  s.TABLE_SCHEMA,
  s.TABLE_NAME
ORDER BY CASE WHEN s.INDEX_NAME = 'PRIMARY' THEN 0 ELSE 1 END, s.INDEX_NAME
`.trim(),
    'List table indexes',
  )
}

export function buildMysqlTableInfoSql(schema: string, table: string): string {
  const schemaLit = quoteMysqlStringLiteral(schema)
  const tableLit = quoteMysqlStringLiteral(table)
  return prefixMysqlSqlComment(
    `
SELECT
  TABLE_SCHEMA AS table_schema,
  TABLE_NAME AS table_name,
  TABLE_TYPE AS table_type,
  (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) AS total_bytes,
  NULLIF(TABLE_COMMENT, '') AS table_comment,
  TABLE_ROWS AS estimated_rows
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = ${schemaLit}
  AND TABLE_NAME = ${tableLit}
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
    const record = rows as Record<string, unknown>
    const values = Object.values(record)
    // Map of row objects: { "0": {...}, "1": {...} } or id-keyed rows.
    // Do not use Object.values on a single column→value row: that turns
    // { schema_name: <bytes> } into [<bytes>] and drops the column name.
    const everyValueIsRowObject =
      values.length > 0 &&
      values.every(
        (value) =>
          value !== null &&
          typeof value === 'object' &&
          !Array.isArray(value),
      )
    if (everyValueIsRowObject) {
      return values as T[]
    }
    return [record as T]
  }
  return []
}

/**
 * True when a value looks like a MySQL driver byte buffer (VARCHAR/CHAR/TEXT
 * often arrives as number[] of UTF-8 code units over the SQL API).
 */
export function isMysqlDriverByteArray(value: unknown): value is number[] {
  if (!Array.isArray(value)) return false
  if (value.length === 0) return true
  return value.every(
    (entry) =>
      typeof entry === 'number' &&
      Number.isInteger(entry) &&
      entry >= 0 &&
      entry <= 255,
  )
}

/** Decode a MySQL driver byte array to UTF-8 text, or null if it looks binary. */
export function decodeMysqlDriverByteArray(bytes: number[]): string | null {
  try {
    const decoded = new TextDecoder('utf-8', { fatal: false }).decode(
      Uint8Array.from(bytes),
    )
    if (!decoded.includes('\uFFFD')) return decoded
    const replacementCount = [...decoded].filter((char) => char === '\uFFFD').length
    // Allow a little corruption; reject mostly-binary payloads.
    if (replacementCount <= Math.max(1, Math.floor(decoded.length * 0.1))) {
      return decoded
    }
    return null
  } catch {
    return null
  }
}

/** Coerce a SQL cell value to a non-empty trimmed string, or null. */
export function coerceMysqlStringValue(value: unknown): string | null {
  if (typeof value === 'string') {
    const trimmed = value.trim()
    return trimmed.length > 0 ? trimmed : null
  }
  if (typeof value === 'number' || typeof value === 'bigint') {
    return String(value)
  }
  if (isMysqlDriverByteArray(value)) {
    const decoded = decodeMysqlDriverByteArray(value)
    if (decoded === null) return null
    const trimmed = decoded.trim()
    return trimmed.length > 0 ? trimmed : null
  }
  if (
    value &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    (value as { type?: unknown }).type === 'Buffer' &&
    isMysqlDriverByteArray((value as { data?: unknown }).data)
  ) {
    return coerceMysqlStringValue((value as { data: number[] }).data)
  }
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null
  }

  const record = value as Record<string, unknown>
  for (const key of [
    'value',
    'Value',
    '$value',
    'text',
    'Text',
    'string',
    'String',
    'schema_name',
    'SCHEMA_NAME',
  ]) {
    if (!(key in record)) continue
    const nested = coerceMysqlStringValue(record[key])
    if (nested) return nested
  }
  return null
}

/**
 * Read a string column from an execution row, matching keys case-insensitively.
 * MySQL result metadata sometimes returns `SCHEMA_NAME` instead of `schema_name`.
 */
export function readMysqlRowString(
  row: Record<string, unknown>,
  ...keys: string[]
): string | null {
  const keyByLower = new Map(
    Object.keys(row).map((key) => [key.toLowerCase(), key] as const),
  )
  for (const key of keys) {
    const actualKey = keyByLower.get(key.toLowerCase())
    if (actualKey === undefined) continue
    const coerced = coerceMysqlStringValue(row[actualKey])
    if (coerced) return coerced
  }
  return null
}

export function formatMysqlRowCount(count: number): string {
  const formatted = count.toLocaleString()
  return `${formatted} row${count === 1 ? '' : 's'}`
}

export function formatMysqlQueryDurationMs(ms: number): string {
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
export function buildMysqlSelectSql(
  schema: string,
  table: string,
  limit: number,
  offset: number,
): string {
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return `SELECT * FROM ${qualified} LIMIT ${limit} OFFSET ${offset}`
}

export function buildMysqlCountSql(schema: string, table: string): string {
  const qualified = `${quoteMysqlIdentifier(schema)}.${quoteMysqlIdentifier(table)}`
  return prefixMysqlSqlComment(
    `SELECT COUNT(*) AS total FROM ${qualified}`,
    'Count table rows',
  )
}

/** Pretty-print SQL for the MySQL SQL editor. Returns the original string on parse errors. */
export function formatMysqlSql(sql: string): string {
  const trimmed = sql.trim()
  if (!trimmed) return sql

  try {
    return format(trimmed, {
      language: 'mysql',
      tabWidth: 2,
      keywordCase: 'upper',
    })
  } catch {
    return sql
  }
}
