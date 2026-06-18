import type { Models } from '@appwrite.io/console'
import { quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import {
  executionResultRows,
  peelLeadingPostgresSqlComments,
  stripLeadingPostgresSqlComments,
} from '@/lib/postgres-sql'

/** Wrapped read-query payload column (internal, not user-facing). */
export const POSTGRES_CONSOLE_RESULT_COLUMN = '__console_result_rows'

const UNSUPPORTED_TYPE_PATTERN = /^<unsupported type ([^>]+)>$/i

const READ_QUERY_PATTERN = /^(with\b|select\b)/i
const MUTATION_QUERY_PATTERN =
  /^(insert\b|update\b|delete\b|create\b|alter\b|drop\b|truncate\b|call\b|do\b|copy\b|grant\b|revoke\b|comment\b|begin\b|commit\b|rollback\b|set\b|vacuum\b|analyze\b|explain\b)/i

function stripTrailingStatementSemicolon(sql: string): string {
  return sql.replace(/;\s*$/, '').trim()
}

/** True when the statement is a read query safe to wrap for JSON display. */
export function isPostgresReadQuery(sql: string): boolean {
  const trimmed = stripTrailingStatementSemicolon(
    stripLeadingPostgresSqlComments(sql.trim()),
  )
  if (!trimmed) return false
  if (MUTATION_QUERY_PATTERN.test(trimmed)) return false
  if (/\bselect\s+into\b/i.test(trimmed)) return false
  return READ_QUERY_PATTERN.test(trimmed)
}

/**
 * Wrap a read query so PostgreSQL serializes every column via row_to_json before
 * the SQL API marshals the response. This avoids "<unsupported type …>" placeholders
 * for NUMERIC, TIMESTAMP, BYTEA, and other non-primitive driver types.
 */
export function wrapPostgresSqlForDisplay(sql: string): string {
  const trimmed = sql.trim()
  const { leadingComments, sqlWithoutLeadingComments } =
    peelLeadingPostgresSqlComments(trimmed)
  const innerSql = stripTrailingStatementSemicolon(sqlWithoutLeadingComments)
  if (!isPostgresReadQuery(sqlWithoutLeadingComments)) return trimmed

  const column = quotePostgresIdentifier(POSTGRES_CONSOLE_RESULT_COLUMN)
  const wrapped = `SELECT coalesce(json_agg(row_to_json(__console_subq)), '[]'::json) AS ${column} FROM (${innerSql}) AS __console_subq`
  if (!leadingComments) return wrapped
  return `${leadingComments}\n${wrapped}`
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
    return `[${value.map((entry) => formatPostgresExecutionCellValue(entry)).join(', ')}]`
  }

  return String(value)
}

/**
 * Normalize a single SQL execution cell value for display/copy.
 * Handles tagged API payloads and common PostgreSQL JSON encodings.
 */
export function formatPostgresExecutionCellValue(value: unknown): string {
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
    return `[${value.map((entry) => formatPostgresExecutionCellValue(entry)).join(', ')}]`
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
    return formatPostgresExecutionCellValue(taggedValue)
  }

  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

export function normalizePostgresExecutionCellValue(value: unknown): unknown {
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
    return value.map((entry) => normalizePostgresExecutionCellValue(entry))
  }

  if (!isPlainObject(value)) {
    return formatPostgresExecutionCellValue(value)
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

  if (taggedType && taggedValue !== undefined) {
    return normalizePostgresExecutionCellValue(taggedValue)
  }

  const normalizedEntries = Object.entries(value).map(([key, entry]) => [
    key,
    normalizePostgresExecutionCellValue(entry),
  ])
  return Object.fromEntries(normalizedEntries)
}

function normalizePostgresExecutionRow(
  row: Record<string, unknown>,
): Record<string, unknown> {
  const normalized: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(row)) {
    normalized[key] = normalizePostgresExecutionCellValue(value)
  }
  return normalized
}

function inferPostgresColumnType(value: unknown): string {
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
      inferPostgresColumnType(firstRow[name]),
  }))
}

function unwrapWrappedPostgresExecution(
  execution: Models.DedicatedDatabaseExecution,
): Models.DedicatedDatabaseExecution | null {
  const rows = executionResultRows<Record<string, unknown>>(execution)
  if (rows.length !== 1) return null

  const payload = rows[0][POSTGRES_CONSOLE_RESULT_COLUMN]
  if (payload === undefined) return null

  const parsedRows = parseJsonArray(payload)
  if (!parsedRows) return null

  const normalizedRows = parsedRows
    .filter(isPlainObject)
    .map((row) => normalizePostgresExecutionRow(row))

  const columns =
    execution.columns?.length === 1 &&
    execution.columns[0]?.name === POSTGRES_CONSOLE_RESULT_COLUMN
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
export function normalizePostgresExecutionResult(
  execution: Models.DedicatedDatabaseExecution,
): Models.DedicatedDatabaseExecution {
  const unwrapped = unwrapWrappedPostgresExecution(execution)
  if (unwrapped) return unwrapped

  const rows = executionResultRows<Record<string, unknown>>(execution).map((row) =>
    normalizePostgresExecutionRow(row),
  )

  return {
    ...execution,
    rows,
    columns: deriveColumnsFromRows(rows, execution.columns ?? []),
    rowCount: rows.length > 0 ? rows.length : execution.rowCount,
  }
}
