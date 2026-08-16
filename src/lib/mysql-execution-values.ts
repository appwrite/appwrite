import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { quoteMysqlIdentifier } from '@/lib/mysql-database-routes'
import {
  decodeMysqlDriverByteArray,
  executionResultRows,
  isMysqlDriverByteArray,
  peelLeadingMysqlSqlComments,
  quoteMysqlStringLiteral,
  stripLeadingMysqlSqlComments,
} from '@/lib/mysql-sql'

/** Wrapped read-query payload column (internal, not user-facing). */
export const MYSQL_CONSOLE_RESULT_COLUMN = '__console_result_rows'

const UNSUPPORTED_TYPE_PATTERN = /^<unsupported type ([^>]+)>$/i

const READ_QUERY_PATTERN = /^(with\b|select\b)/i
const MUTATION_QUERY_PATTERN =
  /^(insert\b|update\b|delete\b|create\b|alter\b|drop\b|truncate\b|call\b|do\b|copy\b|grant\b|revoke\b|comment\b|begin\b|commit\b|rollback\b|set\b|vacuum\b|analyze\b|explain\b)/i

function stripTrailingStatementSemicolon(sql: string): string {
  return sql.replace(/;\s*$/, '').trim()
}

/** True when the statement is a read query safe to wrap for JSON display. */
export function isMysqlReadQuery(sql: string): boolean {
  const trimmed = stripTrailingStatementSemicolon(
    stripLeadingMysqlSqlComments(sql.trim()),
  )
  if (!trimmed) return false
  if (MUTATION_QUERY_PATTERN.test(trimmed)) return false
  if (/\bselect\s+into\b/i.test(trimmed)) return false
  return READ_QUERY_PATTERN.test(trimmed)
}

function buildMysqlJsonObjectExpr(columnNames: readonly string[]): string {
  const seen = new Map<string, number>()
  const parts: string[] = []

  for (const name of columnNames) {
    const baseKey = name.trim() || 'column'
    const count = seen.get(baseKey) ?? 0
    seen.set(baseKey, count + 1)
    const jsonKey = count === 0 ? baseKey : `${baseKey}_${count}`
    parts.push(quoteMysqlStringLiteral(jsonKey), quoteMysqlIdentifier(name))
  }

  return `JSON_OBJECT(${parts.join(', ')})`
}

/**
 * Wrap a read query so MySQL serializes rows as JSON before the SQL API marshals
 * the response. This avoids "<unsupported type …>" placeholders for DATETIME,
 * TIMESTAMP, DECIMAL, and other non-primitive driver types.
 *
 * Column names are required (MySQL has no row_to_json). When omitted, the SQL is
 * returned unchanged so callers can probe once, then retry with a wrap.
 */
export function wrapMysqlSqlForDisplay(
  sql: string,
  columnNames?: readonly string[],
): string {
  const trimmed = sql.trim()
  const { leadingComments, sqlWithoutLeadingComments } =
    peelLeadingMysqlSqlComments(trimmed)
  const innerSql = stripTrailingStatementSemicolon(sqlWithoutLeadingComments)
  if (!isMysqlReadQuery(sqlWithoutLeadingComments)) return trimmed
  if (!columnNames?.length) return trimmed

  const column = quoteMysqlIdentifier(MYSQL_CONSOLE_RESULT_COLUMN)
  const jsonObject = buildMysqlJsonObjectExpr(columnNames)
  const wrapped = `SELECT COALESCE(JSON_ARRAYAGG(${jsonObject}), JSON_ARRAY()) AS ${column} FROM (${innerSql}) AS __console_subq`
  if (!leadingComments) return wrapped
  return `${leadingComments}\n${wrapped}`
}

/** True when the API returned cells that need a JSON wrap to marshal correctly. */
export function mysqlExecutionNeedsJsonWrap(
  execution: Models.DedicatedDatabaseExecution,
): boolean {
  const rows = executionResultRows<Record<string, unknown>>(execution)
  for (const row of rows) {
    for (const value of Object.values(row)) {
      if (typeof value === 'string' && UNSUPPORTED_TYPE_PATTERN.test(value)) {
        return true
      }
      // VARCHAR/TEXT often arrive as raw driver byte arrays.
      if (isMysqlDriverByteArray(value) && value.length > 0) {
        return true
      }
    }
  }
  return false
}

/** @deprecated Use mysqlExecutionNeedsJsonWrap */
export function mysqlExecutionHasUnsupportedTypes(
  execution: Models.DedicatedDatabaseExecution,
): boolean {
  return mysqlExecutionNeedsJsonWrap(execution)
}

/** Column names from execution metadata, falling back to the first row's keys. */
export function getMysqlExecutionColumnNames(
  execution: Models.DedicatedDatabaseExecution,
): string[] {
  const fromMeta = (execution.columns ?? [])
    .map((column) => column.name)
    .filter((name): name is string => typeof name === 'string' && name.length > 0)
  if (fromMeta.length > 0) return fromMeta

  const rows = executionResultRows<Record<string, unknown>>(execution)
  const first = rows[0]
  return first ? Object.keys(first) : []
}

function parseJsonArray(value: unknown): unknown[] | null {
  if (Array.isArray(value)) return value
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  if (!trimmed) return []
  try {
    const parsed: unknown = JSON.parse(trimmed)
    return Array.isArray(parsed) ? parsed : null
  } catch {
    return null
  }
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readTaggedField(
  object: Record<string, unknown>,
  ...keys: string[]
): unknown {
  for (const key of keys) {
    if (key in object) return object[key]
  }
  return undefined
}

function decodeBase64ToUtf8(value: string): string {
  if (typeof atob === 'function') {
    const binary = atob(value)
    const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))
    return new TextDecoder().decode(bytes)
  }

  return value
}

function formatByteaValue(value: unknown): string {
  if (typeof value === 'string') {
    if (value.startsWith('\\x')) {
      const hex = value.slice(2)
      if (hex.length % 2 === 0 && /^[0-9a-f]+$/i.test(hex)) {
        const bytes = Uint8Array.from(
          hex.match(/.{1,2}/g) ?? [],
          (pair) => Number.parseInt(pair, 16),
        )
        const decoded = new TextDecoder().decode(bytes)
        if (decoded && /^[\x20-\x7E\s]+$/.test(decoded)) {
          return decoded
        }
        return `\\x${hex}`
      }
    }
    return value
  }

  if (Array.isArray(value)) {
    if (isMysqlDriverByteArray(value)) {
      const decoded = decodeMysqlDriverByteArray(value)
      if (decoded !== null) return decoded
    }
    return `[${value.map((entry) => formatMysqlExecutionCellValue(entry)).join(', ')}]`
  }

  return String(value)
}

/**
 * Normalize a single SQL execution cell value for display/copy.
 * Handles tagged API payloads and common MySQL JSON encodings.
 */
export function formatMysqlExecutionCellValue(value: unknown): string {
  if (value === null || value === undefined) return 'null'

  if (typeof value === 'string') {
    return value
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }

  if (typeof value === 'bigint') {
    return value.toString()
  }

  if (Array.isArray(value)) {
    if (isMysqlDriverByteArray(value)) {
      const decoded = decodeMysqlDriverByteArray(value)
      if (decoded !== null) return decoded
    }
    return `[${value.map((entry) => formatMysqlExecutionCellValue(entry)).join(', ')}]`
  }

  if (
    isPlainObject(value) &&
    value.type === 'Buffer' &&
    isMysqlDriverByteArray(value.data)
  ) {
    return formatMysqlExecutionCellValue(value.data)
  }

  if (!isPlainObject(value)) {
    return String(value)
  }

  const taggedType = String(
    readTaggedField(value, 'type', 'Type', '$type', 'pgType', 'pg_type') ?? '',
  ).toLowerCase()

  const taggedValue = readTaggedField(
    value,
    'value',
    'Value',
    '$value',
    'text',
    'Text',
    'string',
    'String',
  )

  if (taggedType.includes('bytea') || taggedType === 'bytes') {
    const bytes = readTaggedField(value, 'bytes', 'Bytes', 'data', 'Data')
    if (typeof bytes === 'string') {
      if (value.encoding === 'base64' || value.Encoding === 'base64') {
        return decodeBase64ToUtf8(bytes)
      }
      return formatByteaValue(bytes)
    }
    if (taggedValue !== undefined) {
      return formatByteaValue(taggedValue)
    }
  }

  if (
    taggedType.includes('numeric') ||
    taggedType.includes('decimal') ||
    taggedType.includes('money') ||
    taggedType.includes('int') ||
    taggedType.includes('float') ||
    taggedType.includes('double') ||
    taggedType === 'number'
  ) {
    if (taggedValue !== undefined && taggedValue !== null) {
      return String(taggedValue)
    }
  }

  if (
    taggedType.includes('timestamp') ||
    taggedType.includes('date') ||
    taggedType.includes('time') ||
    taggedType.includes('interval')
  ) {
    if (taggedValue !== undefined && taggedValue !== null) {
      return String(taggedValue)
    }
  }

  if (taggedType.includes('bool')) {
    if (typeof taggedValue === 'boolean') return String(taggedValue)
    if (taggedValue !== undefined && taggedValue !== null) {
      return String(taggedValue)
    }
  }

  if (taggedType.includes('uuid') && taggedValue !== undefined && taggedValue !== null) {
    return String(taggedValue)
  }

  if (taggedType.includes('json') && taggedValue !== undefined) {
    try {
      return typeof taggedValue === 'string'
        ? taggedValue
        : JSON.stringify(taggedValue)
    } catch {
      return String(taggedValue)
    }
  }

  if (taggedValue !== undefined) {
    return formatMysqlExecutionCellValue(taggedValue)
  }

  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

export function normalizeMysqlExecutionCellValue(value: unknown): unknown {
  if (value === null || value === undefined) return null

  if (typeof value === 'string') {
    if (UNSUPPORTED_TYPE_PATTERN.test(value)) return value
    return value
  }

  if (
    typeof value === 'number' ||
    typeof value === 'boolean' ||
    typeof value === 'bigint'
  ) {
    return value
  }

  if (Array.isArray(value)) {
    if (isMysqlDriverByteArray(value)) {
      const decoded = decodeMysqlDriverByteArray(value)
      if (decoded !== null) return decoded
    }
    return value.map((entry) => normalizeMysqlExecutionCellValue(entry))
  }

  if (
    isPlainObject(value) &&
    value.type === 'Buffer' &&
    isMysqlDriverByteArray(value.data)
  ) {
    return normalizeMysqlExecutionCellValue(value.data)
  }

  if (!isPlainObject(value)) {
    return formatMysqlExecutionCellValue(value)
  }

  const taggedType = String(
    readTaggedField(value, 'type', 'Type', '$type', 'pgType', 'pg_type') ?? '',
  ).toLowerCase()

  const taggedValue = readTaggedField(
    value,
    'value',
    'Value',
    '$value',
    'text',
    'Text',
    'string',
    'String',
  )

  // Unwrap driver/API tagged scalars. Some payloads include only `value` /
  // `text` without a `type` field; leaving those as objects breaks callers
  // that expect strings (e.g. schema?.trim() in sidebar table queries).
  if (taggedValue !== undefined && isMysqlTaggedScalarObject(value)) {
    return normalizeMysqlExecutionCellValue(taggedValue)
  }

  if (taggedType && taggedValue !== undefined) {
    return normalizeMysqlExecutionCellValue(taggedValue)
  }

  const normalizedEntries = Object.entries(value).map(([key, entry]) => [
    key,
    normalizeMysqlExecutionCellValue(entry),
  ])
  return Object.fromEntries(normalizedEntries)
}

const MYSQL_TAGGED_SCALAR_KEYS = new Set([
  'type',
  'Type',
  '$type',
  'pgType',
  'pg_type',
  'value',
  'Value',
  '$value',
  'text',
  'Text',
  'string',
  'String',
])

function isMysqlTaggedScalarObject(value: Record<string, unknown>): boolean {
  const keys = Object.keys(value)
  if (keys.length === 0 || keys.length > 3) return false
  return keys.every((key) => MYSQL_TAGGED_SCALAR_KEYS.has(key))
}

function normalizeMysqlExecutionRow(
  row: Record<string, unknown>,
): Record<string, unknown> {
  const normalized: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(row)) {
    normalized[key] = normalizeMysqlExecutionCellValue(value)
  }
  return normalized
}

function inferMysqlColumnType(value: unknown): string {
  if (value === null || value === undefined) return 'text'
  if (typeof value === 'boolean') return 'bool'
  if (typeof value === 'number') return 'float8'
  if (typeof value === 'bigint') return 'int8'
  if (typeof value === 'string') return 'text'
  if (Array.isArray(value)) return 'json'
  if (typeof value === 'object') return 'json'
  return 'text'
}

function deriveColumnsFromRows(
  rows: Record<string, unknown>[],
  fallback: Models.DedicatedDatabaseExecutionColumn[],
): Models.DedicatedDatabaseExecutionColumn[] {
  const firstRow = rows[0]
  if (!firstRow) return fallback

  return Object.keys(firstRow).map((name) => ({
    name,
    type:
      fallback.find((column) => column.name === name)?.type ??
      inferMysqlColumnType(firstRow[name]),
  }))
}

function unwrapWrappedMysqlExecution(
  execution: Models.DedicatedDatabaseExecution,
): Models.DedicatedDatabaseExecution | null {
  const rows = executionResultRows<Record<string, unknown>>(execution)
  if (rows.length !== 1) return null

  const payload = rows[0][MYSQL_CONSOLE_RESULT_COLUMN]
  if (payload === undefined) return null

  const parsedRows = parseJsonArray(payload)
  if (!parsedRows) return null

  const normalizedRows = parsedRows
    .filter(isPlainObject)
    .map((row) => normalizeMysqlExecutionRow(row))

  const columns =
    execution.columns?.length === 1 &&
    execution.columns[0]?.name === MYSQL_CONSOLE_RESULT_COLUMN
      ? deriveColumnsFromRows(normalizedRows, execution.columns)
      : deriveColumnsFromRows(normalizedRows, execution.columns ?? [])

  return {
    ...execution,
    rows: normalizedRows,
    columns,
    rowCount: normalizedRows.length,
  }
}

/** Normalize SQL API execution payloads for console display. */
export function normalizeMysqlExecutionResult(
  execution: Models.DedicatedDatabaseExecution,
): Models.DedicatedDatabaseExecution {
  const unwrapped = unwrapWrappedMysqlExecution(execution)
  if (unwrapped) return unwrapped

  const rows = executionResultRows<Record<string, unknown>>(execution).map((row) =>
    normalizeMysqlExecutionRow(row),
  )

  return {
    ...execution,
    rows,
    columns: deriveColumnsFromRows(rows, execution.columns ?? []),
    rowCount: rows.length > 0 ? rows.length : execution.rowCount,
  }
}
