import type { QueryKey } from '@tanstack/react-query'
import type { FilterMap } from '@/lib/table-filters'
import {
  getUsageFilterColumnIdsForCategory,
  getUsageFilterColumnsForCategory,
  isUsageEventFilterAttribute,
  isUsageEventFilterOperator,
  isUsageGaugeFilterAttribute,
  isUsageGaugeFilterOperator,
  USAGE_FILTER_EXCLUDED_ATTRIBUTES,
} from '@/lib/usage/usage-filter-configs'
import type { FetchUsageOverviewOptions } from '@/lib/usage/usage-events-common'

export type UsageFilterQueries = string[] | undefined

export type UsageFilterQuerySurface = 'events' | 'gauges'

function isFilterKeyAllowedForSurface(
  attribute: string,
  operator: string,
  surface: UsageFilterQuerySurface,
): boolean {
  if (USAGE_FILTER_EXCLUDED_ATTRIBUTES.has(attribute)) return false
  if (surface === 'events') {
    return (
      isUsageEventFilterAttribute(attribute) &&
      isUsageEventFilterOperator(operator)
    )
  }
  return (
    isUsageGaugeFilterAttribute(attribute) &&
    isUsageGaugeFilterOperator(operator)
  )
}

function isFilterKeyAllowedForCategory(
  attribute: string,
  operator: string,
  categoryId: string,
): boolean {
  if (USAGE_FILTER_EXCLUDED_ATTRIBUTES.has(attribute)) return false
  if (!getUsageFilterColumnIdsForCategory(categoryId).has(attribute)) {
    return false
  }
  const column = getUsageFilterColumnsForCategory(categoryId).find(
    (entry) => entry.id === attribute,
  )
  if (column?.allowedOperators?.length) {
    return column.allowedOperators.includes(operator)
  }
  return true
}

export function sanitizeUsageFilterMap(
  filterMap: FilterMap,
  categoryId: string,
): FilterMap {
  if (filterMap.size === 0) return filterMap
  const sanitized = new Map(filterMap)
  for (const key of sanitized.keys()) {
    const attribute = String(key.c)
    if (
      !isFilterKeyAllowedForCategory(attribute, key.o, categoryId)
    ) {
      sanitized.delete(key)
    }
  }
  return sanitized
}

export function getUsageFilterQueriesForSurface(
  filterMap: FilterMap,
  categoryId: string,
  surface: UsageFilterQuerySurface,
): UsageFilterQueries {
  const sanitized = sanitizeUsageFilterMap(filterMap, categoryId)
  if (sanitized.size === 0) return undefined

  const queries: string[] = []
  for (const [key, queryStr] of sanitized) {
    const attribute = String(key.c)
    if (!isFilterKeyAllowedForSurface(attribute, key.o, surface)) continue
    queries.push(queryStr)
  }

  return queries.length > 0 ? queries : undefined
}

export function getUsageFilterQueriesFromMap(
  filterMap: FilterMap,
  categoryId: string,
  surface: UsageFilterQuerySurface = 'events',
): UsageFilterQueries {
  return getUsageFilterQueriesForSurface(filterMap, categoryId, surface)
}

export function appendUsageFiltersToQueryKey(
  key: QueryKey,
  filterQueries: UsageFilterQueries,
): QueryKey {
  if (!filterQueries?.length) return key
  return [...key, 'filters', filterQueries]
}

export function withUsageFetchOptions(
  options: FetchUsageOverviewOptions | undefined,
  filterQueries: UsageFilterQueries,
): FetchUsageOverviewOptions | undefined {
  if (!filterQueries?.length) return options
  return { ...options, queries: filterQueries }
}

export function mergeUsageFetchOptions(
  options: FetchUsageOverviewOptions | undefined,
  filterQueries: UsageFilterQueries,
): FetchUsageOverviewOptions {
  if (!filterQueries?.length) {
    return options ?? {}
  }
  return { ...options, queries: filterQueries }
}

export function usageBreakdownQueries(
  filterQueries: UsageFilterQueries,
): string[] | undefined {
  return filterQueries?.length ? filterQueries : undefined
}
