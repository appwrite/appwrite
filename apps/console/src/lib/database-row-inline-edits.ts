import { DatabaseType } from '@/lib/databases/database-type'
import { isTableColumnStatusPending } from '@/lib/utils/database-columns'

export type RowCellValue = string | number | bigint | boolean | unknown[] | null

export type PendingRowCellEdit = {
  databaseId: string
  tableId: string
  rowId: string
  columnKey: string
  originalValue: RowCellValue
  value: RowCellValue
}

export type ColumnMeta = {
  type?: string
  array?: boolean
  elements?: string[]
  min?: number
  max?: number
  size?: number
  required?: boolean | string
  isRequired?: boolean | string
  nullable?: boolean | string
  status?: string
  encrypt?: boolean
}

export type InlineInputConfig = {
  htmlType: 'text' | 'number' | 'email' | 'url'
  inputMode?: 'numeric' | 'email' | 'url' | 'text'
  min?: number
  max?: number
  step?: number
  maxLength?: number
  placeholder?: string
}

type ParseInlineResult =
  | { ok: true; value: RowCellValue }
  | { ok: false; error: string }

const NON_INLINE_EDITABLE_TYPES = new Set([
  'relationship',
  'point',
  'linestring',
  'polygon',
])

export const SYSTEM_DATE_COLUMN_KEYS = ['$createdAt', '$updatedAt'] as const

export type SystemDateColumnKey = (typeof SYSTEM_DATE_COLUMN_KEYS)[number]

export function isSystemDateColumnKey(
  key: string,
): key is SystemDateColumnKey {
  return key === '$createdAt' || key === '$updatedAt'
}

export function getSystemDateColumnInfo(_key: string): ColumnMeta {
  return { type: 'datetime', required: true }
}

export function resolveInlineColumnInfo(
  columnKey: string,
  columnInfo?: unknown,
): ColumnMeta {
  if (isSystemDateColumnKey(columnKey)) {
    return getSystemDateColumnInfo(columnKey)
  }
  return getColumnMeta(columnInfo)
}

export function getColumnMeta(columnInfo: unknown): ColumnMeta {
  return (columnInfo ?? {}) as ColumnMeta
}

export function makePendingEditKey(
  tableId: string,
  rowId: string,
  columnKey: string,
): string {
  return `${tableId}:${rowId}:${columnKey}`
}

export function getInlineFieldType(
  columnInfo?: unknown,
  value?: RowCellValue,
  columnKey?: string,
): string {
  if (columnKey && isSystemDateColumnKey(columnKey)) return 'datetime'
  const col = getColumnMeta(columnInfo)
  if (col.array) return 'array'
  if (col.type === 'array' || (col.type?.endsWith('[]') ?? false)) {
    return 'array'
  }
  if (col.type) return col.type
  if (Array.isArray(value)) return 'array'
  if (typeof value === 'boolean') return 'boolean'
  if (typeof value === 'number' || typeof value === 'bigint') return 'number'
  return 'string'
}

export function isInlineColumnRequired(columnInfo?: unknown): boolean {
  const col = getColumnMeta(columnInfo)
  return (
    col.required === true ||
    col.required === 'true' ||
    col.isRequired === true ||
    col.isRequired === 'true' ||
    col.nullable === false ||
    col.nullable === 'false'
  )
}

export function isNumericInlineFieldType(type: string): boolean {
  return (
    type === 'integer' ||
    type === 'int' ||
    type === 'bigint' ||
    type === 'double' ||
    type === 'float' ||
    type === 'number'
  )
}

export function isDateTimeInlineFieldType(type: string): boolean {
  return type === 'datetime' || type === 'date'
}

export function isColumnInlineEditable(
  columnInfo: unknown,
  columnKey?: string,
): boolean {
  if (columnKey && isSystemDateColumnKey(columnKey)) return true
  const col = getColumnMeta(columnInfo)
  if (col.array) return false
  if (col.type && NON_INLINE_EDITABLE_TYPES.has(col.type)) return false
  if (isTableColumnStatusPending(col.status)) return false
  return true
}

export function getEnumOptions(columnInfo?: unknown): string[] {
  const col = getColumnMeta(columnInfo)
  return Array.isArray(col.elements) ? col.elements : []
}

export function getInlineInputConfig(
  columnInfo?: unknown,
  columnKey?: string,
): InlineInputConfig {
  const col = getColumnMeta(columnInfo)
  const type = getInlineFieldType(columnInfo, undefined, columnKey)
  const isRequired = isInlineColumnRequired(columnInfo)
  const placeholder = isRequired ? undefined : 'NULL'

  if (type === 'email') {
    return { htmlType: 'email', inputMode: 'email', placeholder }
  }
  if (type === 'url') {
    return { htmlType: 'url', inputMode: 'url', placeholder }
  }
  if (isDateTimeInlineFieldType(type)) {
    return {
      htmlType: 'text',
      placeholder: isRequired ? 'Select date & time' : 'NULL',
    }
  }
  if (type === 'bigint') {
    return { htmlType: 'text', inputMode: 'numeric', placeholder }
  }
  if (isNumericInlineFieldType(type)) {
    return {
      htmlType: 'number',
      inputMode: 'numeric',
      min: col.min,
      max: col.max,
      step: type === 'double' || type === 'float' ? 0.1 : 1,
      placeholder,
    }
  }
  if ((type === 'string' || type === 'varchar') && col.size && col.size > 0) {
    return { htmlType: 'text', maxLength: col.size, placeholder }
  }
  return { htmlType: 'text', placeholder }
}

export function rowCellValuesEqual(a: RowCellValue, b: RowCellValue): boolean {
  if (a === b) return true
  if (a === null || a === undefined) return b === null || b === undefined
  if (b === null || b === undefined) return false
  if (typeof a === 'bigint' || typeof b === 'bigint') {
    try {
      return BigInt(String(a)) === BigInt(String(b))
    } catch {
      return false
    }
  }
  if (Array.isArray(a) || Array.isArray(b)) {
    return JSON.stringify(a) === JSON.stringify(b)
  }
  return a === b
}

function formatDateTimeLocalForInput(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function valueToInlineEditString(
  value: RowCellValue,
  columnInfo?: unknown,
  columnKey?: string,
): string {
  if (value === null || value === undefined) return ''
  const type = getInlineFieldType(columnInfo, value, columnKey)
  if (isDateTimeInlineFieldType(type) && typeof value === 'string') {
    const d = new Date(value)
    if (!Number.isNaN(d.getTime())) return formatDateTimeLocalForInput(d)
  }
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  return String(value)
}

function isEmptyDraft(raw: string): boolean {
  const trimmed = raw.trim()
  return trimmed === '' || trimmed.toLowerCase() === 'null'
}

function validateNumericRange(
  value: number | bigint,
  columnInfo: ColumnMeta,
  type: string,
): string | null {
  if (type === 'bigint') return null
  const num = typeof value === 'bigint' ? Number(value) : value
  if (columnInfo.min !== undefined && num < columnInfo.min) {
    return `Value must be at least ${columnInfo.min}`
  }
  if (columnInfo.max !== undefined && num > columnInfo.max) {
    return `Value must be at most ${columnInfo.max}`
  }
  return null
}

export function parseAndValidateInlineCellInput(
  raw: string,
  columnInfo?: unknown,
  columnKey?: string,
): ParseInlineResult {
  const col = getColumnMeta(columnInfo)
  const type = getInlineFieldType(columnInfo, undefined, columnKey)
  const isRequired = isInlineColumnRequired(columnInfo)
  const trimmed = raw.trim()

  if (isEmptyDraft(raw)) {
    if (isRequired) {
      return { ok: false, error: 'This field is required' }
    }
    return { ok: true, value: null }
  }

  if (type === 'boolean' || type === 'bool') {
    return { ok: true, value: /^(true|1|yes)$/i.test(trimmed) }
  }

  if (type === 'enum') {
    const options = getEnumOptions(columnInfo)
    if (options.length > 0 && !options.includes(trimmed)) {
      return { ok: false, error: 'Select a valid option' }
    }
    return { ok: true, value: trimmed }
  }

  if (type === 'integer' || type === 'int') {
    const n = parseInt(trimmed, 10)
    if (!Number.isFinite(n) || !/^-?\d+$/.test(trimmed)) {
      return { ok: false, error: 'Enter a valid integer' }
    }
    const rangeError = validateNumericRange(n, col, type)
    if (rangeError) return { ok: false, error: rangeError }
    return { ok: true, value: n }
  }

  if (type === 'bigint') {
    try {
      const n = BigInt(trimmed)
      return { ok: true, value: n }
    } catch {
      return { ok: false, error: 'Enter a valid bigint' }
    }
  }

  if (type === 'double' || type === 'float' || type === 'number') {
    const n = parseFloat(trimmed)
    if (!Number.isFinite(n)) {
      return { ok: false, error: 'Enter a valid number' }
    }
    const rangeError = validateNumericRange(n, col, type)
    if (rangeError) return { ok: false, error: rangeError }
    return { ok: true, value: n }
  }

  if (isDateTimeInlineFieldType(type)) {
    const d = new Date(trimmed)
    if (Number.isNaN(d.getTime())) {
      return { ok: false, error: 'Enter a valid date and time' }
    }
    return { ok: true, value: d.toISOString() }
  }

  if (type === 'email') {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
      return { ok: false, error: 'Enter a valid email address' }
    }
    return { ok: true, value: trimmed }
  }

  if (type === 'url') {
    try {
      new URL(trimmed.includes('://') ? trimmed : `https://${trimmed}`)
      return { ok: true, value: trimmed }
    } catch {
      return { ok: false, error: 'Enter a valid URL' }
    }
  }

  if (
    (type === 'string' || type === 'varchar') &&
    col.size &&
    col.size > 0 &&
    trimmed.length > col.size
  ) {
    return {
      ok: false,
      error: `Maximum length is ${col.size} characters`,
    }
  }

  return { ok: true, value: trimmed }
}

export function parseAndValidateInlineCellValue(
  value: RowCellValue,
  columnInfo?: unknown,
  columnKey?: string,
): ParseInlineResult {
  const type = getInlineFieldType(columnInfo, value, columnKey)
  if (value === null || value === undefined) {
    return parseAndValidateInlineCellInput('', columnInfo, columnKey)
  }
  if (type === 'boolean' || type === 'bool') {
    return { ok: true, value: Boolean(value) }
  }
  if (isDateTimeInlineFieldType(type) && typeof value === 'string') {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) {
      return { ok: false, error: 'Enter a valid date and time' }
    }
    return { ok: true, value: d.toISOString() }
  }
  return parseAndValidateInlineCellInput(String(value), columnInfo, columnKey)
}

/** @deprecated Use parseAndValidateInlineCellInput */
export function parseInlineCellInput(
  raw: string,
  columnInfo?: unknown,
  columnKey?: string,
): RowCellValue {
  const result = parseAndValidateInlineCellInput(raw, columnInfo, columnKey)
  return result.ok ? result.value : null
}

export function serializeRowDataForApi(
  data: Record<string, RowCellValue>,
): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(data)) {
    if (typeof value === 'bigint') {
      out[key] = value.toString()
    } else {
      out[key] = value
    }
  }
  return out
}

export function usesCollectionDocumentIds(kind: DatabaseType): boolean {
  return kind === DatabaseType.Documentsdb || kind === DatabaseType.Vectorsdb
}

export function groupEditsIntoUpdateOperations(
  edits: PendingRowCellEdit[],
  kind: DatabaseType = DatabaseType.Tablesdb,
): object[] {
  const byRow = new Map<string, PendingRowCellEdit[]>()
  for (const edit of edits) {
    const key = `${edit.databaseId}:${edit.tableId}:${edit.rowId}`
    const list = byRow.get(key)
    if (list) list.push(edit)
    else byRow.set(key, [edit])
  }

  const operations: object[] = []
  for (const rowEdits of byRow.values()) {
    const first = rowEdits[0]
    const data: Record<string, unknown> = {}
    for (const edit of rowEdits) {
      const serialized = serializeRowDataForApi({ [edit.columnKey]: edit.value })
      data[edit.columnKey] = serialized[edit.columnKey]
    }
    operations.push(
      usesCollectionDocumentIds(kind)
        ? {
            action: 'update',
            databaseId: first.databaseId,
            collectionId: first.tableId,
            documentId: first.rowId,
            data,
          }
        : {
            action: 'update',
            databaseId: first.databaseId,
            tableId: first.tableId,
            rowId: first.rowId,
            data,
          },
    )
  }
  return operations
}
