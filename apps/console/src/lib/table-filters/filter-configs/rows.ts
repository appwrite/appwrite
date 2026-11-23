/**
 * Build filter columns for table rows from table attributes (columns).
 * Includes system fields and all table attributes with a supported type.
 * When indexes are provided, columns that are part of a fulltext index get fulltextSearchable.
 */

import type { FilterColumn, FilterColumnType } from '../types'

type TableAttribute = {
  key: string
  name?: string
  type?: string
  elements?: Array<{ value: string | number; label?: string }>
  /** When false, attribute can be null (enum/optional); when true or unset, required. */
  required?: boolean
}

/** Index shape from API (key, type, columns). */
export type TableIndexForFilters = {
  type?: string
  columns?: string[]
}

function mapAttributeTypeToFilterType(type?: string): FilterColumnType {
  switch (type) {
    case 'integer':
      return 'integer'
    case 'bigint':
      return 'bigint'
    case 'double':
    case 'float':
      return 'double'
    case 'boolean':
      return 'boolean'
    case 'datetime':
      return 'datetime'
    case 'enum':
      return 'enum'
    case 'string':
    case 'varchar':
    case 'text':
    case 'email':
    case 'ip':
    case 'url':
    default:
      return 'string'
  }
}

/** System fields always available on rows */
const ROWS_SYSTEM_COLUMNS: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
  { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
]

/**
 * Build filter columns for the rows view from table attributes.
 * Use this when you have the table's columns (attributes) from the API.
 * When indexes are provided, columns that appear in a fulltext index get fulltextSearchable: true.
 */
export function rowsFilterColumnsFromAttributes(
  attributes: TableAttribute[] | undefined,
  indexes?: TableIndexForFilters[],
): FilterColumn[] {
  const fulltextColumnKeys = new Set<string>()
  if (indexes?.length) {
    for (const idx of indexes) {
      if (idx.type === 'fulltext' && Array.isArray(idx.columns)) {
        for (const key of idx.columns) {
          if (key && typeof key === 'string') fulltextColumnKeys.add(key)
        }
      }
    }
  }

  if (!attributes?.length) {
    return ROWS_SYSTEM_COLUMNS
  }

  const fromAttrs: FilterColumn[] = attributes
    .filter((a) => a.key && !a.key.startsWith('$'))
    .map((attr) => {
      const type = mapAttributeTypeToFilterType(attr.type)
      const col: FilterColumn = {
        id: attr.key,
        title: attr.name || attr.key,
        type,
        fulltextSearchable: fulltextColumnKeys.has(attr.key),
      }
      if (type === 'enum' && attr.elements?.length) {
        col.elements = attr.elements.map((e) => ({
          value: e.value,
          label: e.label ?? String(e.value),
        }))
      }
      if (type === 'enum') {
        col.optional = attr.required === false
      }
      return col
    })

  return [...ROWS_SYSTEM_COLUMNS, ...fromAttrs]
}

/** Reserved filter column id: opens “custom attribute” name + value type (Documents DB only). */
export const DOCUMENTS_DB_CUSTOM_ATTRIBUTE_FILTER_COLUMN_ID =
  '__documentsDbCustomAttribute' as const

/**
 * Append the “Custom attribute” filter option for unstructured Documents DB payloads.
 * Tables DB and Vectors DB should not call this.
 */
export function appendDocumentsDbCustomAttributeFilter(
  columns: FilterColumn[],
): FilterColumn[] {
  const slot: FilterColumn = {
    id: DOCUMENTS_DB_CUSTOM_ATTRIBUTE_FILTER_COLUMN_ID,
    title: 'Custom attribute',
    type: 'string',
    customAttributeSlot: true,
  }
  return [...columns, slot]
}
