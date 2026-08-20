/**
 * Filter column configs for the project usage section.
 * Allowed attributes and operators mirror appwrite-labs/cloud Usage Events/Gauges XList.
 */

import type { FilterColumn } from '@/lib/table-filters'

/** listEvents filter attributes (teamId excluded from console UI). */
export const USAGE_EVENT_FILTER_ATTRIBUTES = [
  'path',
  'method',
  'status',
  'service',
  'resourceType',
  'resourceId',
  'country',
  'city',
  'hostname',
  'ip',
  'osName',
  'clientType',
  'clientName',
  'deviceName',
  'sdk',
  'sdkVersion',
] as const

/** listGauges filter attributes (teamId excluded from console UI). */
export const USAGE_GAUGE_FILTER_ATTRIBUTES = [
  'service',
  'resourceType',
  'resourceId',
] as const

export type UsageEventFilterAttribute =
  (typeof USAGE_EVENT_FILTER_ATTRIBUTES)[number]

export type UsageGaugeFilterAttribute =
  (typeof USAGE_GAUGE_FILTER_ATTRIBUTES)[number]

/** listEvents query operators. */
export const USAGE_EVENT_FILTER_OPERATORS = [
  'equal',
  'notEqual',
  'contains',
  'startsWith',
  'endsWith',
  'isNull',
  'isNotNull',
] as const

/** listGauges query operators. */
export const USAGE_GAUGE_FILTER_OPERATORS = [
  'equal',
  'notEqual',
  'isNull',
  'isNotNull',
] as const

export type UsageEventFilterOperator =
  (typeof USAGE_EVENT_FILTER_OPERATORS)[number]

export type UsageGaugeFilterOperator =
  (typeof USAGE_GAUGE_FILTER_OPERATORS)[number]

/** Attributes excluded from usage filters in the console UI. */
export const USAGE_FILTER_EXCLUDED_ATTRIBUTES = new Set(['teamId'])

export type UsageFilterQuerySurface = 'events' | 'gauges' | 'mixed'

export type UsageFilterAvailability = {
  /** Cloud supports city filtering; the current self-hosted endpoint does not. */
  allowCity?: boolean
}

const HTTP_METHODS = [
  'GET',
  'POST',
  'PUT',
  'PATCH',
  'DELETE',
  'OPTIONS',
  'HEAD',
].map((method) => ({ value: method, label: method }))

const NETWORK_SERVICES = [
  'account',
  'avatars',
  'databases',
  'documentsDB',
  'functions',
  'graphql',
  'health',
  'locale',
  'messaging',
  'project',
  'storage',
  'tablesDB',
  'teams',
  'tokens',
  'users',
  'vectorsDB',
  'webhooks',
].map((service) => ({ value: service, label: service }))

const DATABASE_SERVICES = [
  'tablesDB',
  'documentsDB',
  'vectorsDB',
  'databases',
].map((service) => ({ value: service, label: service }))

const COMPUTE_SERVICES = [
  { value: 'functions', label: 'functions' },
  { value: 'sites', label: 'sites' },
]

const EVENT_OPERATORS = [...USAGE_EVENT_FILTER_OPERATORS]
const GAUGE_OPERATORS = [...USAGE_GAUGE_FILTER_OPERATORS]

function stringColumn(
  id: UsageEventFilterAttribute | UsageGaugeFilterAttribute,
  title: string,
  operators: readonly string[] = EVENT_OPERATORS,
): FilterColumn {
  return { id, title, type: 'string', allowedOperators: [...operators] }
}

function enumColumn(
  id: UsageEventFilterAttribute | UsageGaugeFilterAttribute,
  title: string,
  elements: Array<{ value: string; label: string }>,
  operators: readonly string[] = EVENT_OPERATORS,
): FilterColumn {
  return {
    id,
    title,
    type: 'enum',
    format: 'enum',
    elements,
    optional: false,
    allowedOperators: [...operators],
  }
}

/** Network request/bandwidth breakdown dimensions (listEvents). */
const NETWORK_EVENT_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('path', 'Path'),
  enumColumn('method', 'Method', HTTP_METHODS),
  stringColumn('status', 'Status'),
  enumColumn('service', 'Service', NETWORK_SERVICES),
  stringColumn('resourceType', 'Resource type'),
  stringColumn('resourceId', 'Resource ID'),
  stringColumn('country', 'Country'),
  stringColumn('city', 'Caller city'),
  stringColumn('hostname', 'Hostname'),
  stringColumn('ip', 'IP address'),
  stringColumn('osName', 'Operating system'),
  stringColumn('clientType', 'Client type'),
  stringColumn('clientName', 'Client name'),
  stringColumn('deviceName', 'Device name'),
  stringColumn('sdk', 'SDK'),
  stringColumn('sdkVersion', 'SDK version'),
]

/** Storage gauge metrics (listGauges). */
const STORAGE_GAUGE_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('resourceId', 'Resource ID', GAUGE_OPERATORS),
  stringColumn('resourceType', 'Resource type', GAUGE_OPERATORS),
  enumColumn(
    'service',
    'Service',
    [{ value: 'storage', label: 'storage' }],
    GAUGE_OPERATORS,
  ),
]

/** Compute executions / GB-hours (listEvents). */
const COMPUTE_EVENT_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('resourceId', 'Resource ID'),
  stringColumn('resourceType', 'Resource type'),
  enumColumn('service', 'Service', COMPUTE_SERVICES),
]

/** Function executions / GB-hours (listEvents). */
const FUNCTIONS_EVENT_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('resourceId', 'Function ID'),
  stringColumn('resourceType', 'Resource type'),
]

/** Site executions / GB-hours (listEvents). */
const SITES_EVENT_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('resourceId', 'Site ID'),
  stringColumn('resourceType', 'Resource type'),
]

/** Database reads/writes + collection/document gauges (mixed). */
const DATABASES_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('resourceId', 'Resource ID', GAUGE_OPERATORS),
  enumColumn('service', 'Service', DATABASE_SERVICES, GAUGE_OPERATORS),
  stringColumn('path', 'API path'),
  stringColumn('resourceType', 'Resource type', GAUGE_OPERATORS),
  stringColumn('ip', 'IP address'),
]

/** Realtime metrics (listEvents, project-scoped). */
const REALTIME_EVENT_FILTER_COLUMNS: FilterColumn[] = [
  stringColumn('resourceId', 'Resource ID'),
]

/** Auth MAU/sign-ups (gauges) and OTP (events). */
const AUTH_FILTER_COLUMNS: FilterColumn[] = [
  enumColumn(
    'service',
    'Service',
    [
      { value: 'users', label: 'users' },
      { value: 'account', label: 'account' },
    ],
    GAUGE_OPERATORS,
  ),
  stringColumn('resourceId', 'Resource ID', GAUGE_OPERATORS),
  stringColumn('resourceType', 'Resource type', GAUGE_OPERATORS),
]

/** Avatars screenshots (listEvents). */
const AVATARS_EVENT_FILTER_COLUMNS: FilterColumn[] = [
  enumColumn('service', 'Service', [{ value: 'avatars', label: 'avatars' }]),
  stringColumn('path', 'Path'),
  stringColumn('resourceId', 'Resource ID'),
]

/** Messaging messages/SMS (events) and topics (gauges). */
const MESSAGING_FILTER_COLUMNS: FilterColumn[] = [
  enumColumn(
    'service',
    'Service',
    [{ value: 'messaging', label: 'messaging' }],
    GAUGE_OPERATORS,
  ),
  stringColumn('resourceId', 'Resource ID', GAUGE_OPERATORS),
  stringColumn('resourceType', 'Resource type', GAUGE_OPERATORS),
]

/** Webhook deliveries (events) and webhook count (gauges). */
const WEBHOOKS_FILTER_COLUMNS: FilterColumn[] = [
  enumColumn(
    'service',
    'Service',
    [{ value: 'webhooks', label: 'webhooks' }],
    GAUGE_OPERATORS,
  ),
  stringColumn('resourceId', 'Resource ID', GAUGE_OPERATORS),
  stringColumn('resourceType', 'Resource type', GAUGE_OPERATORS),
]

const CATEGORY_FILTER_COLUMNS: Record<string, FilterColumn[]> = {
  requests: NETWORK_EVENT_FILTER_COLUMNS,
  bandwidth: NETWORK_EVENT_FILTER_COLUMNS,
  storage: STORAGE_GAUGE_FILTER_COLUMNS,
  compute: COMPUTE_EVENT_FILTER_COLUMNS,
  functions: FUNCTIONS_EVENT_FILTER_COLUMNS,
  sites: SITES_EVENT_FILTER_COLUMNS,
  databases: DATABASES_FILTER_COLUMNS,
  realtime: REALTIME_EVENT_FILTER_COLUMNS,
  auth: AUTH_FILTER_COLUMNS,
  avatars: AVATARS_EVENT_FILTER_COLUMNS,
  messaging: MESSAGING_FILTER_COLUMNS,
  webhooks: WEBHOOKS_FILTER_COLUMNS,
}

const CATEGORY_QUERY_SURFACE: Record<string, UsageFilterQuerySurface> = {
  requests: 'events',
  bandwidth: 'events',
  storage: 'gauges',
  compute: 'events',
  functions: 'events',
  sites: 'events',
  databases: 'mixed',
  realtime: 'events',
  auth: 'mixed',
  avatars: 'events',
  messaging: 'mixed',
  webhooks: 'mixed',
}

export function getUsageQuerySurfaceForCategory(
  categoryId: string,
): UsageFilterQuerySurface {
  return CATEGORY_QUERY_SURFACE[categoryId] ?? 'events'
}

export function getUsageFilterColumnsForCategory(
  categoryId: string,
  availability: UsageFilterAvailability = {},
): FilterColumn[] {
  const columns = CATEGORY_FILTER_COLUMNS[categoryId] ?? []
  return availability.allowCity === false
    ? columns.filter((column) => column.id !== 'city')
    : columns
}

export function getUsageFilterColumnIdsForCategory(
  categoryId: string,
  availability: UsageFilterAvailability = {},
): Set<string> {
  return new Set(
    getUsageFilterColumnsForCategory(categoryId, availability).map(
      (column) => column.id,
    ),
  )
}

export function isUsageFilterDimensionAllowed(
  categoryId: string,
  dimension: string,
  availability: UsageFilterAvailability = {},
): boolean {
  if (USAGE_FILTER_EXCLUDED_ATTRIBUTES.has(dimension)) return false
  return getUsageFilterColumnIdsForCategory(categoryId, availability).has(
    dimension,
  )
}

export function getUsageSavedFilterScope(categoryId: string): string {
  return `usage.${categoryId}`
}

export function categorySupportsUsageFilters(categoryId: string): boolean {
  return categoryId in CATEGORY_FILTER_COLUMNS
}

export function isUsageEventFilterAttribute(
  attribute: string,
): attribute is UsageEventFilterAttribute {
  return (USAGE_EVENT_FILTER_ATTRIBUTES as readonly string[]).includes(
    attribute,
  )
}

export function isUsageGaugeFilterAttribute(
  attribute: string,
): attribute is UsageGaugeFilterAttribute {
  return (USAGE_GAUGE_FILTER_ATTRIBUTES as readonly string[]).includes(
    attribute,
  )
}

export function isUsageEventFilterOperator(
  operator: string,
): operator is UsageEventFilterOperator {
  return (USAGE_EVENT_FILTER_OPERATORS as readonly string[]).includes(operator)
}

export function isUsageGaugeFilterOperator(
  operator: string,
): operator is UsageGaugeFilterOperator {
  return (USAGE_GAUGE_FILTER_OPERATORS as readonly string[]).includes(operator)
}
