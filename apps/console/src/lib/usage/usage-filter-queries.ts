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
  type UsageFilterAvailability,
} from '@/lib/usage/usage-filter-configs'
import type { FetchUsageOverviewOptions } from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'

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
  availability: UsageFilterAvailability,
): boolean {
  if (USAGE_FILTER_EXCLUDED_ATTRIBUTES.has(attribute)) return false
  if (
    !getUsageFilterColumnIdsForCategory(categoryId, availability).has(attribute)
  ) {
    return false
  }
  const column = getUsageFilterColumnsForCategory(
    categoryId,
    availability,
  ).find((entry) => entry.id === attribute)
  if (column?.allowedOperators?.length) {
    return column.allowedOperators.includes(operator)
  }
  return true
}

export function sanitizeUsageFilterMap(
  filterMap: FilterMap,
  categoryId: string,
  availability: UsageFilterAvailability = {},
): FilterMap {
  if (filterMap.size === 0) return filterMap
  const sanitized = new Map(filterMap)
  for (const key of sanitized.keys()) {
    const attribute = String(key.c)
    if (
      !isFilterKeyAllowedForCategory(attribute, key.o, categoryId, availability)
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
  availability: UsageFilterAvailability = {},
): UsageFilterQueries {
  const sanitized = sanitizeUsageFilterMap(filterMap, categoryId, availability)
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
  availability: UsageFilterAvailability = {},
): UsageFilterQueries {
  return getUsageFilterQueriesForSurface(
    filterMap,
    categoryId,
    surface,
    availability,
  )
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
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): FetchUsageOverviewOptions {
  const withRetention: FetchUsageOverviewOptions = {
    logRetentionHours,
    ...options,
  }
  if (!filterQueries?.length) {
    return withRetention
  }
  return { ...withRetention, queries: filterQueries }
}

export function usageBreakdownQueries(
  filterQueries: UsageFilterQueries,
): string[] | undefined {
  return filterQueries?.length ? filterQueries : undefined
}
