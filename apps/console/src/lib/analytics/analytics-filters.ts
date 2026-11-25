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
  'browserVersion',
  'operatingSystem',
  'operatingSystemVersion',
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
  'trafficType',
  'eventName',
  'userId',
] as const

type AnalyticsColumnFilterAttribute = (typeof ANALYTICS_FILTER_ATTRIBUTES)[number]

/** A custom event prop, filtered as `props.<key>`. */
export type AnalyticsPropFilterAttribute = `props.${string}`

export type AnalyticsFilterAttribute =
  | AnalyticsColumnFilterAttribute
  | AnalyticsPropFilterAttribute

const PROP_FILTER_PREFIX = 'props.'

export function analyticsPropFilterAttribute(
  key: string,
): AnalyticsPropFilterAttribute {
  return `${PROP_FILTER_PREFIX}${key}`
}

/** The prop key behind a `props.<key>` attribute, or null for a column. */
export function analyticsPropKeyOf(attribute: string): string | null {
  return attribute.startsWith(PROP_FILTER_PREFIX) &&
    attribute.length > PROP_FILTER_PREFIX.length
    ? attribute.slice(PROP_FILTER_PREFIX.length)
    : null
}

const ATTRIBUTE_TITLES: Record<AnalyticsColumnFilterAttribute, string> = {
  country: 'Country',
  region: 'Region',
  city: 'City',
  browser: 'Browser',
  browserVersion: 'Browser version',
  operatingSystem: 'Operating system',
  operatingSystemVersion: 'OS version',
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
  trafficType: 'Traffic',
  eventName: 'Event',
  userId: 'User',
}

/** `trafficType` takes only its labels (`human` / `bot`), so no pattern operators. */
const TRAFFIC_TYPE_OPERATORS = ['equal', 'notEqual'] as const

export const ANALYTICS_FILTER_COLUMNS: FilterColumn[] =
  ANALYTICS_FILTER_ATTRIBUTES.map((id) => ({
    id,
    title: ATTRIBUTE_TITLES[id],
    type: 'string',
    allowedOperators:
      id === 'trafficType'
        ? [...TRAFFIC_TYPE_OPERATORS]
        : [...ANALYTICS_FILTER_OPERATORS],
  }))

/**
 * The filter columns plus one per active `props.<key>` filter, titled with the
 * key, so chips, exports and the filter editor can resolve it.
 */
export function analyticsFilterColumns(
  filters: readonly AnalyticsFilter[],
): FilterColumn[] {
  const propAttributes = new Set(
    filters
      .map((filter) => filter.attribute)
      .filter((attribute) => analyticsPropKeyOf(attribute) !== null),
  )
  if (propAttributes.size === 0) return ANALYTICS_FILTER_COLUMNS
  return [
    ...ANALYTICS_FILTER_COLUMNS,
    ...[...propAttributes].map(
      (id): FilterColumn => ({
        id,
        title: analyticsPropKeyOf(id)!,
        type: 'string',
        allowedOperators: [...ANALYTICS_FILTER_OPERATORS],
      }),
    ),
  ]
}

const ATTRIBUTE_SET = new Set<string>(ANALYTICS_FILTER_ATTRIBUTES)
const OPERATOR_SET = new Set<string>(ANALYTICS_FILTER_OPERATORS)
const TRAFFIC_OPERATOR_SET = new Set<string>(TRAFFIC_TYPE_OPERATORS)

export function isAnalyticsFilterAttribute(
  value: string,
): value is AnalyticsFilterAttribute {
  return ATTRIBUTE_SET.has(value) || analyticsPropKeyOf(value) !== null
}

/**
 * Which attribute a breakdown row filters on when clicked. Most dimensions
 * share their attribute name; the rest have no matching query attribute:
 * `entryPage` / `exitPage` are session-derived, not event fields.
 */
export function analyticsFilterAttributeForDimension(
  dimension: AnalyticsDimension,
): AnalyticsFilterAttribute | null {
  if (
    dimension === AnalyticsDimension.EntryPage ||
    dimension === AnalyticsDimension.ExitPage
  ) {
    return null
  }
  return isAnalyticsFilterAttribute(dimension) ? dimension : null
}

/** Which attribute a breakdown tab's rows filter on; prop tabs use `props.<key>`. */
export function analyticsFilterAttributeForTab(tab: {
  dimension: AnalyticsDimension
  propKey?: string
}): AnalyticsFilterAttribute | null {
  return tab.propKey
    ? analyticsPropFilterAttribute(tab.propKey)
    : analyticsFilterAttributeForDimension(tab.dimension)
}

/** Drop anything the API wouldn't accept (stale or hand-edited URLs). */
export function sanitizeAnalyticsFilterMap(filterMap: FilterMap): FilterMap {
  if (filterMap.size === 0) return filterMap
  const sanitized: FilterMap = new Map()
  for (const [key, query] of filterMap) {
    const operators =
      key.c === 'trafficType' ? TRAFFIC_OPERATOR_SET : OPERATOR_SET
    if (isAnalyticsFilterAttribute(String(key.c)) && operators.has(key.o)) {
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

// ─── Humans / bots ──────────────────────────────────────────────────────────
//
// The humans/bots split filters on `trafficType`, which the API maps to its
// stored `is_bot` flag.

export type AnalyticsTrafficKind = 'human' | 'bot'

export function trafficKindFilterEntry(kind: AnalyticsTrafficKind): {
  key: CompactFilterKey
  query: string
} {
  return {
    key: { c: 'trafficType', o: 'equal', v: kind },
    query: buildFilterQueryString('equal', 'trafficType', kind),
  }
}

function isTrafficKind(value: unknown): value is AnalyticsTrafficKind {
  return value === 'human' || value === 'bot'
}

/** The humans/bots filter a key represents, if it is one. */
export function trafficKindOfFilterKey(
  key: CompactFilterKey,
): AnalyticsTrafficKind | null {
  if (key.c === 'trafficType' && isTrafficKind(key.v)) {
    if (key.o === 'equal') return key.v
    if (key.o === 'notEqual') return key.v === 'human' ? 'bot' : 'human'
    return null
  }
  // Older links filtered on an empty vs non-empty bot category.
  if (key.c !== 'botCategory' || key.v !== '') return null
  if (key.o === 'equal') return 'human'
  if (key.o === 'notEqual') return 'bot'
  return null
}

/**
 * Events-only attributes (no column on the sessions table) are only accepted
 * "alongside `interval`, or with a breakdown on a dimension other than
 * entryPage, exitPage". The flat aggregate (summary stats, live counter) and
 * entry/exit page breakdowns therefore can't honour them.
 */
const SHAPE_RESTRICTED_ATTRIBUTES = new Set<AnalyticsFilterAttribute>([
  'page',
  'eventName',
  'userId',
])

function isShapeRestricted(attribute: AnalyticsFilterAttribute): boolean {
  return (
    SHAPE_RESTRICTED_ATTRIBUTES.has(attribute) ||
    analyticsPropKeyOf(attribute) !== null
  )
}

export type AnalyticsQueryShape =
  | { kind: 'flat' }
  | { kind: 'series' }
  | { kind: 'breakdown'; dimension: AnalyticsDimension }

export function isAnalyticsFilterShapeSupported(
  filters: readonly AnalyticsFilter[],
  shape: AnalyticsQueryShape,
): boolean {
  if (!filters.some((filter) => isShapeRestricted(filter.attribute))) {
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
  'Not available with this filter'
