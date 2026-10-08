/**
 * Client-side filtering for in-memory arrays (e.g. table columns, table indexes).
 * Evaluates CompactFilterKey conditions against plain records.
 */

import type { CompactFilterKey } from './types'

function getRecordValue(
  record: Record<string, unknown>,
  columnId: string,
): unknown {
  const v = record[columnId]
  return v
}

/**
 * Returns true if the record matches the given compact filter key.
 */
export function recordMatchesCompactKey(
  record: Record<string, unknown>,
  key: CompactFilterKey,
): boolean {
  const colVal = getRecordValue(record, key.c)
  const filterVal = key.v
  const strCol = String(colVal ?? '')
  const strFilter = String(filterVal ?? '')

  switch (key.o) {
    case 'equal':
      if (Array.isArray(filterVal)) {
        return Array.isArray(colVal)
          ? filterVal.some((f) => colVal.includes(f))
          : filterVal.includes(String(colVal))
      }
      return String(colVal) === String(filterVal)
    case 'notEqual':
      return String(colVal) !== String(filterVal)
    case 'startsWith':
      return strCol.toLowerCase().startsWith(strFilter.toLowerCase())
    case 'notStartsWith':
      return !strCol.toLowerCase().startsWith(strFilter.toLowerCase())
    case 'endsWith':
      return strCol.toLowerCase().endsWith(strFilter.toLowerCase())
    case 'notEndsWith':
      return !strCol.toLowerCase().endsWith(strFilter.toLowerCase())
    case 'contains':
      return strCol.toLowerCase().includes(strFilter.toLowerCase())
    case 'notContains':
      return !strCol.toLowerCase().includes(strFilter.toLowerCase())
    case 'greaterThan':
      return Number(colVal) > Number(filterVal)
    case 'greaterThanEqual':
      return Number(colVal) >= Number(filterVal)
    case 'lessThan':
      return Number(colVal) < Number(filterVal)
    case 'lessThanEqual':
      return Number(colVal) <= Number(filterVal)
    case 'isNull':
    case 'notExists':
      return colVal == null || colVal === ''
    case 'isNotNull':
    case 'exists':
      return colVal != null && colVal !== ''
    default:
      return String(colVal) === String(filterVal)
  }
}

/**
 * Filter an array of records by a filter map (e.g. from URL query param).
 * All conditions must match (AND).
 */
export function filterRecordsByCompactMap<T extends Record<string, unknown>>(
  records: T[],
  filterMap: Map<CompactFilterKey, string>,
): T[] {
  if (filterMap.size === 0) return records
  return records.filter((record) =>
    Array.from(filterMap.keys()).every((key) =>
      recordMatchesCompactKey(record, key),
    ),
  )
}
