/**
 * Page-level filters for the analytics property view.
 *
 * Same URL contract as the Usage section (`?query=` holding compact
 * `FilterMap` keys, see `@/lib/table-filters`), so the shared FiltersPopover,
 * saved filters and chip rendering all work unchanged.
 *
 * Allowed attributes and operators mirror `analytics.listMetrics` `queries[]`.
 */

import { AnalyticsDimension } from '@appwrite.io/console'
import {
  buildFilterQueryString,
  type CompactFilterKey,
  type FilterColumn,
  type FilterMap,
} from '@/lib/table-filters'

export const ANALYTICS_FILTER_OPERATORS = [
  'equal',
  'notEqual',
  'contains',
  'startsWith',
  'endsWith',
] as const

/** Attributes `listMetrics` accepts in `queries[]`. */
export const ANALYTICS_FILTER_ATTRIBUTES = [
  'country',
  'region',
  'city',
  'browser',
  'operatingSystem',
  'device',
  'screenSize',
  'referrerSource',
  'channel',
  'utmSource',
  'utmMedium',
  'utmCampaign',
  'utmContent',
  'utmTerm',
  'page',
  'hostname',
  'botName',
  'botCategory',
  'eventName',
] as const

export type AnalyticsFilterAttribute =
  (typeof ANALYTICS_FILTER_ATTRIBUTES)[number]

const ATTRIBUTE_TITLES: Record<AnalyticsFilterAttribute, string> = {
  country: 'Country',
  region: 'Region',
  city: 'City',
  browser: 'Browser',
  operatingSystem: 'Operating system',
  device: 'Device',
  screenSize: 'Screen size',
  referrerSource: 'Source',
  channel: 'Channel',
  utmSource: 'UTM source',
  utmMedium: 'UTM medium',
  utmCampaign: 'Campaign',
  utmContent: 'UTM content',
  utmTerm: 'UTM term',
  page: 'Page',
  hostname: 'Hostname',
  botName: 'Bot',
  botCategory: 'Bot category',
  eventName: 'Event',
}

export const ANALYTICS_FILTER_COLUMNS: FilterColumn[] =
  ANALYTICS_FILTER_ATTRIBUTES.map((id) => ({
    id,
    title: ATTRIBUTE_TITLES[id],
    type: 'string',
    allowedOperators: [...ANALYTICS_FILTER_OPERATORS],
  }))

const ATTRIBUTE_SET = new Set<string>(ANALYTICS_FILTER_ATTRIBUTES)
const OPERATOR_SET = new Set<string>(ANALYTICS_FILTER_OPERATORS)

export function isAnalyticsFilterAttribute(
  value: string,
): value is AnalyticsFilterAttribute {
  return ATTRIBUTE_SET.has(value)
}

/**
 * Which attribute a breakdown row filters on when clicked. Most dimensions
 * share their attribute name; the rest have no matching query attribute:
 * - `entryPage` / `exitPage` are session-derived, not event fields.
 * - `trafficType` isn't accepted in `queries[]`.
 */
export function analyticsFilterAttributeForDimension(
  dimension: AnalyticsDimension,
): AnalyticsFilterAttribute | null {
  if (
    dimension === AnalyticsDimension.EntryPage ||
    dimension === AnalyticsDimension.ExitPage ||
    dimension === AnalyticsDimension.TrafficType
  ) {
    return null
  }
  return isAnalyticsFilterAttribute(dimension) ? dimension : null
}

/** Drop anything the API wouldn't accept (stale or hand-edited URLs). */
export function sanitizeAnalyticsFilterMap(filterMap: FilterMap): FilterMap {
  if (filterMap.size === 0) return filterMap
  const sanitized: FilterMap = new Map()
  for (const [key, query] of filterMap) {
    if (isAnalyticsFilterAttribute(String(key.c)) && OPERATOR_SET.has(key.o)) {
      sanitized.set(key, query)
    }
  }
  return sanitized
}

/** One active filter, as the query layer needs it. */
export type AnalyticsFilter = {
  attribute: AnalyticsFilterAttribute
  query: string
}

export function analyticsFiltersFromMap(filterMap: FilterMap): AnalyticsFilter[] {
  const filters: AnalyticsFilter[] = []
  for (const [key, query] of filterMap) {
    const attribute = String(key.c)
    if (isAnalyticsFilterAttribute(attribute)) {
      filters.push({ attribute, query })
    }
  }
  return filters
}

export function equalFilterEntry(
  attribute: AnalyticsFilterAttribute,
  value: string,
): { key: CompactFilterKey; query: string } {
  return {
    key: { c: attribute, o: 'equal', v: value },
    query: buildFilterQueryString('equal', attribute, value),
  }
}

/**
 * `page` and `eventName` are only accepted "alongside `interval`, or with a
 * breakdown on a dimension other than entryPage, exitPage". The flat
 * aggregate (summary stats, live counter) and entry/exit page breakdowns
 * therefore can't honour them.
 */
const SHAPE_RESTRICTED_ATTRIBUTES = new Set<AnalyticsFilterAttribute>([
  'page',
  'eventName',
])

export type AnalyticsQueryShape =
  | { kind: 'flat' }
  | { kind: 'series' }
  | { kind: 'breakdown'; dimension: AnalyticsDimension }

export function isAnalyticsFilterShapeSupported(
  filters: readonly AnalyticsFilter[],
  shape: AnalyticsQueryShape,
): boolean {
  if (!filters.some((filter) => SHAPE_RESTRICTED_ATTRIBUTES.has(filter.attribute))) {
    return true
  }
  if (shape.kind === 'series') return true
  if (shape.kind === 'breakdown') {
    return (
      shape.dimension !== AnalyticsDimension.EntryPage &&
      shape.dimension !== AnalyticsDimension.ExitPage
    )
  }
  return false
}

/** Query strings for a request, or undefined when there are no filters. */
export function analyticsFilterQueries(
  filters: readonly AnalyticsFilter[],
): string[] | undefined {
  return filters.length > 0 ? filters.map((filter) => filter.query) : undefined
}

/** Stable query-key segment; empty when unfiltered so loader keys still match. */
export function analyticsFiltersKey(
  filters: readonly AnalyticsFilter[],
): string[] {
  return filters.length > 0
    ? ['filters', ...filters.map((filter) => filter.query).sort()]
    : []
}

export const ANALYTICS_FILTER_UNSUPPORTED_MESSAGE =
  'Not available with a page or event filter'
