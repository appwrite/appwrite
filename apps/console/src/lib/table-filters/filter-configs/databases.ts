/**
 * Predefined filter columns for project databases list.
 */

import { DatabaseType } from '@/lib/databases/database-type'
import { isCloudProfile } from '@/lib/console-profiles'
import { formatDatabaseServiceLabel } from '@/lib/databases/database-service-icons'
import { buildFilterQueryString } from '../operators'
import { findCompactFilterKeyInMap } from '../url'
import type { CompactFilterKey, FilterColumn, FilterMap } from '../types'

export const DATABASE_TYPE_FILTER_COLUMN_ID = 'type'

const DATABASE_TYPE_ORDER: DatabaseType[] = [
  DatabaseType.Tablesdb,
  DatabaseType.Documentsdb,
  DatabaseType.Vectorsdb,
]

function databaseTypeFilterElements(): Array<{ value: string; label: string }> {
  const types: DatabaseType[] = [DatabaseType.Tablesdb]
  if (isCloudProfile()) {
    types.push(DatabaseType.Documentsdb, DatabaseType.Vectorsdb)
  }
  return types.map((value) => ({
    value,
    label: formatDatabaseServiceLabel(value) ?? value,
  }))
}

/** Filter columns for the databases list (type options depend on profile). */
export function getDatabasesFilterColumns(): FilterColumn[] {
  const columns: FilterColumn[] = [
    { id: '$id', title: '$id', type: 'string' },
    { id: 'name', title: 'Name', type: 'string' },
  ]
  if (isCloudProfile()) {
    columns.push({
      id: DATABASE_TYPE_FILTER_COLUMN_ID,
      title: 'Type',
      type: 'enum',
      format: 'enum',
      elements: databaseTypeFilterElements(),
      optional: false,
    })
  }
  columns.push(
    { id: '$createdAt', title: '$createdAt', type: 'datetime' },
    { id: '$updatedAt', title: '$updatedAt', type: 'datetime' },
  )
  return columns
}

/** @deprecated Prefer getDatabasesFilterColumns() for type-aware options. */
export const databasesFilterColumns: FilterColumn[] = getDatabasesFilterColumns()

/** Available database types for the droplist (same set as filter column elements). */
export function getDatabaseTypeFilterOptions(): Array<{
  value: DatabaseType
  label: string
}> {
  return databaseTypeFilterElements().map((el) => ({
    value: el.value as DatabaseType,
    label: el.label,
  }))
}

function normalizeTypeValues(
  value: CompactFilterKey['v'],
): DatabaseType[] {
  if (value == null) return []
  const raw = Array.isArray(value) ? value : [value]
  const allowed = new Set<string>(DATABASE_TYPE_ORDER)
  const selected = new Set<DatabaseType>()
  for (const item of raw) {
    const key = String(item)
    if (allowed.has(key)) selected.add(key as DatabaseType)
  }
  return DATABASE_TYPE_ORDER.filter((type) => selected.has(type))
}

/** Selected database types from the URL/API filter map. */
export function getSelectedDatabaseTypesFromFilterMap(
  filterMap: FilterMap,
): DatabaseType[] {
  for (const key of filterMap.keys()) {
    if (key.c === DATABASE_TYPE_FILTER_COLUMN_ID && key.o === 'equal') {
      return normalizeTypeValues(key.v)
    }
  }
  return []
}

/** Remove all type filters from a map (by value copy). */
export function omitDatabaseTypeFilters(filterMap: FilterMap): FilterMap {
  const next = new Map(filterMap)
  for (const key of [...next.keys()]) {
    if (key.c === DATABASE_TYPE_FILTER_COLUMN_ID) {
      next.delete(key)
    }
  }
  return next
}

/**
 * Write selected database types into the filter map (replaces any existing type filters).
 * Empty selection clears the type filter (show all types).
 */
export function setSelectedDatabaseTypesInFilterMap(
  filterMap: FilterMap,
  types: DatabaseType[],
): FilterMap {
  const next = omitDatabaseTypeFilters(filterMap)
  const selected = normalizeTypeValues(types)
  if (selected.length === 0) return next

  const value: string | string[] =
    selected.length === 1 ? selected[0]! : selected
  const compactKey: CompactFilterKey = {
    c: DATABASE_TYPE_FILTER_COLUMN_ID,
    o: 'equal',
    v: value,
  }
  // Drop any leftover key that equals by value but differs by reference.
  const existing = findCompactFilterKeyInMap(next, compactKey)
  if (existing) next.delete(existing)

  next.set(
    compactKey,
    buildFilterQueryString('equal', DATABASE_TYPE_FILTER_COLUMN_ID, value),
  )
  return next
}
