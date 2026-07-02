/**
 * Table filters – operator definitions and Query string builder (see TABLE_FILTERS_AND_SEARCH.md).
 *
 * Operators are tied to column types; only show operators valid for the selected column.
 */

import { Query } from '@appwrite.io/console'
import { formatBinaryBytes } from '@/lib/utils/byte-display-unit'
import type {
  CompactFilterKey,
  FilterColumn,
  FilterColumnType,
  FilterOperatorDef,
  FilterTagValue,
} from './types'

export const FILTER_OPERATORS: FilterOperatorDef[] = [
  {
    key: 'equal',
    label: 'equal',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'enum',
      'point',
      'linestring',
      'polygon',
      'varchar',
      'text',
    ],
  },
  {
    key: 'notEqual',
    label: 'not equal',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'enum',
      'point',
      'linestring',
      'polygon',
      'varchar',
      'text',
    ],
  },
  {
    key: 'startsWith',
    label: 'starts with',
    types: ['string', 'varchar', 'text'],
  },
  {
    key: 'notStartsWith',
    label: 'not starts with',
    types: ['string', 'varchar', 'text'],
  },
  { key: 'endsWith', label: 'ends with', types: ['string', 'varchar', 'text'] },
  {
    key: 'notEndsWith',
    label: 'not ends with',
    types: ['string', 'varchar', 'text'],
  },
  {
    key: 'contains',
    label: 'contains',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'point',
      'linestring',
      'polygon',
      'varchar',
      'text',
    ],
  },
  {
    key: 'notContains',
    label: 'not contains',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'point',
      'linestring',
      'polygon',
      'varchar',
      'text',
    ],
  },
  { key: 'search', label: 'search', types: ['string', 'varchar', 'text'] },
  {
    key: 'notSearch',
    label: 'does not match search',
    types: ['string', 'varchar', 'text'],
  },
  {
    key: 'regex',
    label: 'matches regex',
    types: ['string', 'varchar', 'text'],
  },
  {
    key: 'greaterThan',
    label: 'greater than',
    types: ['integer', 'bigint', 'double', 'datetime'],
  },
  {
    key: 'greaterThanEqual',
    label: 'greater than or equal',
    types: ['integer', 'bigint', 'double', 'datetime'],
  },
  {
    key: 'lessThan',
    label: 'less than',
    types: ['integer', 'bigint', 'double', 'datetime'],
  },
  {
    key: 'lessThanEqual',
    label: 'less than or equal',
    types: ['integer', 'bigint', 'double', 'datetime'],
  },
  {
    key: 'between',
    label: 'between',
    types: ['integer', 'bigint', 'double', 'datetime'],
  },
  {
    key: 'notBetween',
    label: 'not between',
    types: ['integer', 'bigint', 'double', 'datetime'],
  },
  {
    key: 'isNull',
    label: 'is null',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'enum',
      'varchar',
      'text',
    ],
    noValue: true,
  },
  {
    key: 'isNotNull',
    label: 'is not null',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'enum',
      'varchar',
      'text',
    ],
    noValue: true,
  },
  {
    key: 'exists',
    label: 'exists',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'varchar',
      'text',
    ],
    noValue: true,
  },
  {
    key: 'notExists',
    label: 'does not exist',
    types: [
      'string',
      'integer',
      'bigint',
      'double',
      'boolean',
      'datetime',
      'varchar',
      'text',
    ],
    noValue: true,
  },
]

/** Get operators allowed for a column type. Excludes search/notSearch unless fulltextSearchable. For enum, excludes is null / is not null unless optional. */
export function getOperatorsForType(
  columnType: FilterColumnType,
  options?: { fulltextSearchable?: boolean; enumOptional?: boolean },
): FilterOperatorDef[] {
  let base = FILTER_OPERATORS.filter((op) => op.types.includes(columnType))
  if (options?.fulltextSearchable !== true) {
    base = base.filter((op) => op.key !== 'search' && op.key !== 'notSearch')
  }
  if (columnType === 'enum' && options?.enumOptional !== true) {
    base = base.filter((op) => op.key !== 'isNull' && op.key !== 'isNotNull')
  }
  return base
}

/** Operators for a filter column, honoring optional allowedOperators allowlists. */
export function getOperatorsForColumn(
  column: FilterColumn,
  valueTypeOverride?: FilterColumnType,
): FilterOperatorDef[] {
  const columnType = valueTypeOverride ?? column.type
  const base = getOperatorsForType(columnType, {
    fulltextSearchable: column.customAttributeSlot ? false : !!column.fulltextSearchable,
    enumOptional: columnType === 'enum' ? column.optional : undefined,
  })
  if (!column.allowedOperators?.length) return base
  const allowed = new Set(column.allowedOperators)
  return base.filter((op) => allowed.has(op.key))
}

/** Parse "start,end" for between/notBetween; returns [start, end] or null if invalid. */
function parseBetweenValue(
  value: string | number | string[] | boolean | null | undefined,
): [string, string] | null {
  const s =
    typeof value === 'string'
      ? value
      : Array.isArray(value)
        ? value.join(',')
        : String(value ?? '')
  const parts = s
    .split(',')
    .map((p) => p.trim())
    .filter(Boolean)
  if (parts.length >= 2) return [parts[0], parts[1]]
  return null
}

/**
 * Build a single Query condition string from operator + column + value.
 * Used when adding a filter to the map; the result is stored as the map value.
 */
export function buildFilterQueryString(
  operatorKey: string,
  columnId: string,
  value: string | number | string[] | boolean | null | undefined,
): string {
  const safeVal = value ?? ''
  switch (operatorKey) {
    case 'equal':
      return Array.isArray(safeVal)
        ? Query.equal(columnId, safeVal)
        : Query.equal(columnId, safeVal)
    case 'notEqual':
      return Query.notEqual(columnId, safeVal as string | number | boolean)
    case 'startsWith':
      return Query.startsWith(columnId, String(safeVal))
    case 'notStartsWith':
      return Query.notStartsWith(columnId, String(safeVal))
    case 'endsWith':
      return Query.endsWith(columnId, String(safeVal))
    case 'notEndsWith':
      return Query.notEndsWith(columnId, String(safeVal))
    case 'contains':
      return Array.isArray(safeVal)
        ? Query.contains(columnId, safeVal)
        : Query.contains(columnId, String(safeVal))
    case 'notContains':
      return Array.isArray(safeVal)
        ? Query.notContains(columnId, safeVal)
        : Query.notContains(columnId, String(safeVal))
    case 'search':
      return Query.search(columnId, String(safeVal))
    case 'notSearch':
      return Query.notSearch(columnId, String(safeVal))
    case 'regex':
      return Query.regex(columnId, String(safeVal))
    case 'greaterThan':
      return Query.greaterThan(columnId, safeVal as string | number)
    case 'greaterThanEqual':
      return Query.greaterThanEqual(columnId, safeVal as string | number)
    case 'lessThan':
      return Query.lessThan(columnId, safeVal as string | number)
    case 'lessThanEqual':
      return Query.lessThanEqual(columnId, safeVal as string | number)
    case 'between': {
      const pair = parseBetweenValue(value)
      if (pair) return Query.between(columnId, pair[0], pair[1])
      return Query.equal(columnId, safeVal as string)
    }
    case 'notBetween': {
      const pair = parseBetweenValue(value)
      if (pair) return Query.notBetween(columnId, pair[0], pair[1])
      return Query.notEqual(columnId, safeVal as string)
    }
    case 'isNull':
      return Query.isNull(columnId)
    case 'isNotNull':
      return Query.isNotNull(columnId)
    case 'exists':
      return Query.exists([columnId])
    case 'notExists':
      return Query.notExists([columnId])
    default:
      return Query.equal(columnId, safeVal as string | number | boolean)
  }
}

/**
 * Build display tag for a filter: "**columnTitle** operatorLabel **value**" or "**columnTitle** is null".
 * For between/not between, value "start, end" is shown as "start and end".
 */
export function buildFilterTag(
  columnTitle: string,
  operatorLabel: string,
  value: string | number | string[] | null | undefined,
): FilterTagValue {
  if (
    value == null ||
    value === '' ||
    (Array.isArray(value) && value.length === 0)
  ) {
    return { tag: `**${columnTitle}** ${operatorLabel}`, value: '' }
  }
  let display = Array.isArray(value) ? value.join(', ') : String(value)
  const isBetween =
    operatorLabel === 'between' || operatorLabel === 'not between'
  if (isBetween && display.includes(',')) {
    display = display
      .split(',')
      .map((p) => p.trim())
      .filter(Boolean)
      .join(' and ')
  }
  return {
    tag: `**${columnTitle}** ${operatorLabel} **${display}**`,
    value: value as string | number | string[],
  }
}

/**
 * Build display tag from compact key (e.g. when reading filters from URL).
 * Use for rendering filter tags when the map key is CompactFilterKey.
 */
export function buildFilterTagFromCompactKey(
  key: CompactFilterKey,
  columns: FilterColumn[],
): FilterTagValue {
  const col = columns.find((c) => c.id === key.c)
  if (!col) {
    const opDef = FILTER_OPERATORS.find((o) => o.key === key.o)
    const opLabel = opDef?.label ?? key.o
    let tagDisplayVal = ''
    if (key.v !== undefined && key.v !== '') {
      tagDisplayVal = Array.isArray(key.v) ? key.v.join(', ') : String(key.v)
    }
    return buildFilterTag(String(key.c), opLabel, tagDisplayVal || undefined)
  }
  const ops = getOperatorsForColumn(col)
  const op = ops.find((o) => o.key === key.o)
  const opLabel = op?.label ?? key.o
  if (op?.noValue) return buildFilterTag(col.title, opLabel, undefined)
  let tagDisplayVal: string
  if (col.id === 'status' && (key.v === true || key.v === false)) {
    tagDisplayVal = key.v ? 'Enabled' : 'Disabled'
  } else if (col.format === 'size' && typeof key.v === 'number') {
    tagDisplayVal = formatBytesForFilter(key.v)
  } else if (key.v !== undefined && key.v !== '') {
    tagDisplayVal = Array.isArray(key.v) ? key.v.join(', ') : String(key.v)
  } else {
    tagDisplayVal = ''
  }
  return buildFilterTag(col.title, opLabel, tagDisplayVal || undefined)
}

/** Format byte count for filter tag display (e.g. 1048576 → "1 MB"). */
function formatBytesForFilter(bytes: number): string {
  return formatBinaryBytes(bytes)
}
