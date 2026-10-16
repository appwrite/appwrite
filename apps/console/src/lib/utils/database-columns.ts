/**
 * Text-like column types (string, varchar, text, mediumtext, longtext).
 * Used for display name eligibility, filters, row cell component selection.
 */
export const TEXT_TYPES = [
  'string',
  'varchar',
  'text',
  'mediumtext',
  'longtext',
] as const

export type TextColumnType = (typeof TEXT_TYPES)[number]

/**
 * Returns true for any string-like column type.
 */
export function isTextType(type: string | undefined): boolean {
  if (!type) return false
  return TEXT_TYPES.includes(type.toLowerCase() as TextColumnType)
}

/**
 * Column types that have a "size" attribute (shown in columns list, used for index key length).
 * Only string and varchar (not format variants like email/url/enum, not $id).
 */
export const SIZED_STRING_TYPES = ['string', 'varchar'] as const

export function hasColumnSize(type: string | undefined): boolean {
  if (!type) return false
  return SIZED_STRING_TYPES.includes(type.toLowerCase() as 'string' | 'varchar')
}

/** Signed 64-bit bounds for Tables DB `bigint` columns. */
export const INT64_MIN = -9223372036854775808n
export const INT64_MAX = 9223372036854775807n

export function parseInt64Value(raw: string): bigint | null {
  const trimmed = raw.trim()
  if (!trimmed) return null
  try {
    return BigInt(trimmed)
  } catch {
    return null
  }
}

export function isValidInt64(value: bigint): boolean {
  return value >= INT64_MIN && value <= INT64_MAX
}

export function formatInt64Bound(
  value: number | bigint | undefined | null,
): string {
  if (value === undefined || value === null) return ''
  return String(value)
}

/** Tables DB column lifecycle status from the API. */
export type TableColumnStatus =
  | 'available'
  | 'processing'
  | 'deleting'
  | 'stuck'
  | 'failed'
  | string

export function normalizeTableColumnStatus(
  status: string | undefined | null,
): TableColumnStatus {
  if (!status) return 'available'
  return status
}

export function isTableColumnStatusPending(status: string | undefined): boolean {
  const normalized = normalizeTableColumnStatus(status)
  return normalized !== 'available'
}

export function getTableColumnStatusBadgeVariant(
  status: string | undefined,
): 'success' | 'processing' | 'error' | 'warning' {
  switch (normalizeTableColumnStatus(status)) {
    case 'available':
      return 'success'
    case 'deleting':
    case 'failed':
    case 'stuck':
      return 'error'
    case 'processing':
    default:
      return 'processing'
  }
}

export function getTableColumnKey(col: unknown): string {
  const c = col as Record<string, unknown>
  return String(c.key ?? c.name ?? c.$id ?? c.attribute ?? c.attributeId ?? '')
}

/** Built-in Tables DB row fields (not returned by listColumns). */
export type TablesDbSystemColumnDefinition = {
  key: '$id' | '$createdAt' | '$updatedAt'
  type: string
  required: boolean
}

export const TABLESDB_SYSTEM_COLUMNS: readonly TablesDbSystemColumnDefinition[] =
  [
    { key: '$id', type: 'string', required: true },
    { key: '$createdAt', type: 'datetime', required: true },
    { key: '$updatedAt', type: 'datetime', required: true },
  ]

export function isTablesDbSystemColumnKey(key: string): boolean {
  return (
    key === '$id' || key === '$createdAt' || key === '$updatedAt'
  )
}

export type MappedTableColumnListItem = {
  key: string
  type: string
  format: string | null
  size: number | null
  encrypt: boolean
  min?: unknown
  max?: unknown
  elements: string[] | null
  relatedTableId?: unknown
  relationshipType?: unknown
  twoWay?: unknown
  twoWayKey?: unknown
  onDelete?: unknown
  required: boolean
  array: boolean
  default: unknown
  xdefault: unknown
  status: TableColumnStatus
  error: string
  $id: string
}

export function buildTablesDbSystemColumnListItem(
  def: TablesDbSystemColumnDefinition,
): MappedTableColumnListItem {
  return {
    key: def.key,
    type: def.type,
    format: null,
    size: null,
    encrypt: false,
    elements: null,
    required: def.required,
    array: false,
    default: null,
    xdefault: null,
    status: 'available',
    error: '',
    $id: def.key,
  }
}

/** Prepends all built-in system columns before user-defined columns. */
export function mergeTablesDbSystemColumnsIntoList(
  userColumns: MappedTableColumnListItem[],
): MappedTableColumnListItem[] {
  const systemColumns = TABLESDB_SYSTEM_COLUMNS.map((def) =>
    buildTablesDbSystemColumnListItem(def),
  )
  const filteredUser = userColumns.filter(
    (column) => !isTablesDbSystemColumnKey(column.key),
  )
  return [...systemColumns, ...filteredUser]
}

const NUMERIC_RANGE_COLUMN_TYPES = new Set([
  'integer',
  'int',
  'bigint',
  'double',
  'float',
  'number',
])

export function columnTypeSupportsNumericRange(type: string | undefined): boolean {
  if (!type) return false
  return NUMERIC_RANGE_COLUMN_TYPES.has(type.toLowerCase())
}

const INTEGER_LIKE_COLUMN_TYPES = new Set(['integer', 'int', 'bigint'])

export function isIntegerLikeColumnType(type: string | undefined): boolean {
  if (!type) return false
  return INTEGER_LIKE_COLUMN_TYPES.has(type.toLowerCase())
}

/** Comma-grouped integer string (e.g. 9,223,372,036,854,775,807). */
export function formatIntegerLikeGrouped(value: number | bigint): string {
  if (typeof value === 'bigint') {
    const negative = value < 0n
    const abs = negative ? -value : value
    const grouped = abs
      .toString()
      .replace(/\B(?=(\d{3})+(?!\d))/g, ',')
    return negative ? `-${grouped}` : grouped
  }
  return new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0,
  }).format(value)
}

function integerLikeRawString(value: number | bigint): string {
  return typeof value === 'bigint' ? value.toString() : String(Math.trunc(value))
}

/** Use scientific notation (e.g. 9.22e+18) when the full decimal string is long. */
export function shouldAbbreviateIntegerLike(
  value: number | bigint,
  columnType?: string,
): boolean {
  if (!isIntegerLikeColumnType(columnType)) return false
  const digits = integerLikeRawString(value).replace(/^-/, '')
  return digits.length > 6
}

/** Scientific notation with lowercase `e+` exponent, e.g. 9.22e+18 */
export function formatScientificIntegerLike(value: number | bigint): string {
  if (typeof value === 'bigint') {
    if (value === 0n) return '0'
    const negative = value < 0n
    const abs = negative ? -value : value
    const digits = abs.toString()
    const exp = digits.length - 1
    const d0 = Number(digits[0] ?? 0)
    const d1 = Number(digits[1] ?? 0)
    const d2 = Number(digits[2] ?? 0)
    const mantissa = d0 + d1 / 10 + d2 / 100
    return `${negative ? '-' : ''}${mantissa.toFixed(2)}e+${exp}`
  }

  const n = value
  if (!Number.isFinite(n) || n === 0) return String(n)
  const exp = Math.floor(Math.log10(Math.abs(n)))
  const mantissa = n / 10 ** exp
  const sign = mantissa < 0 ? '-' : ''
  return `${sign}${Math.abs(mantissa).toFixed(2)}e+${exp}`
}

export type ColumnNumericDisplay = {
  /** Short label for table cells (scientific or comma-grouped) */
  label: string
  /** Comma-grouped value(s) for the hover popover */
  detail?: string
  /** Raw digits copied from the popover */
  copyValue?: string
  /** Multiple values (e.g. min/max range) with per-row copy */
  entries?: Array<{
    label?: string
    detail: string
    copyValue: string
  }>
}

export type ColumnRangeDisplay = ColumnNumericDisplay

/** Parse API/default values for integer-like column types. */
export function parseColumnIntegerLikeValue(
  value: unknown,
  columnType?: string,
): number | bigint | null {
  if (value === null || value === undefined) return null
  const type = columnType?.toLowerCase()
  if (!type || !INTEGER_LIKE_COLUMN_TYPES.has(type)) return null

  if (type === 'bigint') {
    if (typeof value === 'bigint') return value
    if (typeof value === 'number' && Number.isFinite(value)) {
      return BigInt(Math.trunc(value))
    }
    if (typeof value === 'string') return parseInt64Value(value)
    return null
  }

  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.trunc(value)
  }
  if (typeof value === 'string') {
    const trimmed = value.trim()
    if (!trimmed) return null
    const parsed = Number(trimmed)
    return Number.isFinite(parsed) ? Math.trunc(parsed) : null
  }
  return null
}

function formatIntegerLikeCellPart(
  value: number | bigint,
  columnType?: string,
): {
  label: string
  detail?: string
  copyValue: string
  abbreviated: boolean
} {
  const copyValue = integerLikeRawString(value)
  const grouped = formatIntegerLikeGrouped(value)
  const abbreviated = shouldAbbreviateIntegerLike(value, columnType)
  if (abbreviated) {
    return {
      label: formatScientificIntegerLike(value),
      detail: grouped,
      copyValue,
      abbreviated: true,
    }
  }
  return { label: grouped, copyValue, abbreviated: false }
}

/** Display for a single integer/bigint value (e.g. column default). */
export function formatColumnNumericDisplay(
  value: unknown,
  columnType?: string,
): ColumnNumericDisplay | null {
  const parsed = parseColumnIntegerLikeValue(value, columnType)
  if (parsed === null) return null
  const part = formatIntegerLikeCellPart(parsed, columnType)
  if (!part.abbreviated) {
    return { label: part.label, copyValue: part.copyValue }
  }
  return {
    label: part.label,
    entries: [
      {
        label: 'Default',
        detail: part.detail,
        copyValue: part.copyValue,
      },
    ],
  }
}

type RangeBoundPart = {
  boundLabel: string
  label: string
  detail?: string
  copyValue: string
  abbreviated: boolean
}

function rangeDisplayFromParts(
  label: string,
  parts: RangeBoundPart[],
): ColumnRangeDisplay {
  const showPopover =
    parts.some((p) => p.abbreviated) || parts.length > 1
  if (!showPopover) {
    return { label }
  }
  return {
    label,
    entries: parts.map((p) => ({
      label: p.boundLabel,
      detail: p.detail ?? p.label,
      copyValue: p.copyValue,
    })),
  }
}

function formatRangeCellPart(
  value: number | bigint,
  columnType?: string,
): {
  label: string
  detail?: string
  copyValue: string
  abbreviated: boolean
} {
  if (!isIntegerLikeColumnType(columnType)) {
    const label = String(value)
    return { label, copyValue: label, abbreviated: false }
  }
  return formatIntegerLikeCellPart(value, columnType)
}

/** Human-readable min/max for integer, bigint, and float columns. */
export function formatColumnRangeDisplay(
  min: number | bigint | null | undefined,
  max: number | bigint | null | undefined,
  columnType?: string,
): ColumnRangeDisplay | null {
  const hasMin = min !== undefined && min !== null
  const hasMax = max !== undefined && max !== null
  if (!hasMin && !hasMax) return null

  if (hasMin && hasMax) {
    const minPart = formatRangeCellPart(min as number | bigint, columnType)
    const maxPart = formatRangeCellPart(max as number | bigint, columnType)
    return rangeDisplayFromParts(
      `${minPart.label} – ${maxPart.label}`,
      [
        { boundLabel: 'Min', ...minPart },
        { boundLabel: 'Max', ...maxPart },
      ],
    )
  }
  if (hasMin) {
    const minPart = formatRangeCellPart(min as number | bigint, columnType)
    return rangeDisplayFromParts(`≥ ${minPart.label}`, [
      { boundLabel: 'Min', ...minPart },
    ])
  }
  const maxPart = formatRangeCellPart(max as number | bigint, columnType)
  return rangeDisplayFromParts(`≤ ${maxPart.label}`, [
    { boundLabel: 'Max', ...maxPart },
  ])
}
