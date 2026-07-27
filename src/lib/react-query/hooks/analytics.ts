/**
 * React Query hooks for Analytics (web analytics properties, stats and events)
 *
 * The Analytics API is project-scoped (`sdk.forProject(projectId).analytics`).
 * All list/detail data is fetched through exported functions so route loaders and
 * hooks share the exact same query configuration (see AGENTS.md "QueryOptions Pattern").
 */

import {
  queryOptions,
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, isClientQueryEnabled } from './constants'

/**
 * Date range shorthands accepted by the Analytics API (`dateRange` query param).
 * The API takes a shorthand string, not an arbitrary from/to pair, so the UI
 * offers a fixed set of ranges instead of a calendar range picker.
 */
export const ANALYTICS_DATE_RANGES = ['24h', '7d', '30d', '90d'] as const

export type AnalyticsDateRange = (typeof ANALYTICS_DATE_RANGES)[number]

/**
 * Default range used by both route loaders and views. Must stay in sync so the
 * prefetched query key matches what the view requests (no layout shift).
 */
export const DEFAULT_ANALYTICS_DATE_RANGE: AnalyticsDateRange = '30d'

/** Conventional event name used for the pageview time series. */
export const ANALYTICS_PAGEVIEW_EVENT = 'pageview'

export function isAnalyticsDateRange(
  value: string | undefined | null,
): value is AnalyticsDateRange {
  return !!value && (ANALYTICS_DATE_RANGES as readonly string[]).includes(value)
}

/** Zero-filled metric used while loading or when a property has no data yet. */
export const EMPTY_ANALYTICS_METRIC: Models.AnalyticsMetric = {
  visitors: 0,
  sessions: 0,
  visits: 0,
  pageviews: 0,
  events: 0,
  bounceRate: 0,
  visitDuration: 0,
  viewsPerVisit: 0,
  scrollDepth: 0,
  engagementTime: 0,
}

/**
 * One point of the daily time series returned by `getEventMetrics`.
 *
 * NOTE: `Models.AnalyticsMetricList.metrics` is typed as a bare `object` in the
 * SDK, so the time-series shape is not expressed by the generated types. We
 * declare this narrow type and validate defensively at the boundary rather than
 * spreading `any` through the component tree.
 */
export type AnalyticsMetricPoint = {
  /** ISO date (or whatever bucket label the API returns) for this point. */
  date: string
  events: number
  visitors: number
  sessions: number
}

function toFiniteNumber(value: unknown): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string') {
    const parsed = Number(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return 0
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function pickDateLabel(entry: Record<string, unknown>): string | undefined {
  for (const key of ['date', 'period', 'time', 'timestamp', '$id']) {
    const value = entry[key]
    if (typeof value === 'string' && value.trim()) return value
    if (typeof value === 'number' && Number.isFinite(value)) {
      return new Date(value).toISOString()
    }
  }
  return undefined
}

function pointFromRecord(
  entry: Record<string, unknown>,
  fallbackDate?: string,
): AnalyticsMetricPoint | undefined {
  const date = pickDateLabel(entry) ?? fallbackDate
  if (!date) return undefined
  return {
    date,
    events: toFiniteNumber(entry.events ?? entry.count ?? entry.value),
    visitors: toFiniteNumber(entry.visitors),
    sessions: toFiniteNumber(entry.sessions ?? entry.visits),
  }
}

/**
 * Defensively normalize the untyped `metrics` payload into a sorted time series.
 *
 * Handles both plausible encodings (an array of points, or an object keyed by
 * date) and drops anything that does not parse, so a server-side shape change
 * degrades to an empty chart instead of a runtime crash.
 */
export function parseAnalyticsMetricSeries(
  metrics: unknown,
): AnalyticsMetricPoint[] {
  const points: AnalyticsMetricPoint[] = []

  if (Array.isArray(metrics)) {
    for (const entry of metrics) {
      if (!isRecord(entry)) continue
      const point = pointFromRecord(entry)
      if (point) points.push(point)
    }
  } else if (isRecord(metrics)) {
    for (const [key, value] of Object.entries(metrics)) {
      if (isRecord(value)) {
        const point = pointFromRecord(value, key)
        if (point) points.push(point)
      } else if (typeof value === 'number' || typeof value === 'string') {
        const numeric = toFiniteNumber(value)
        points.push({
          date: key,
          events: numeric,
          visitors: 0,
          sessions: 0,
        })
      }
    }
  }

  return points.sort((a, b) => a.date.localeCompare(b.date))
}

// ─── Properties ─────────────────────────────────────────────────────────────

export async function fetchAnalyticsProperties(projectId: string) {
  if (!projectId) {
    return { properties: [] as Models.AnalyticsProperty[], total: 0 }
  }

  const response = await sdk.forProject(projectId).analytics.listProperties()

  return {
    properties: response.properties || [],
    total: response.total || 0,
  }
}

export function analyticsPropertiesQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['analytics', 'properties', projectId],
    queryFn: () => fetchAnalyticsProperties(projectId!),
    enabled: !!projectId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsProperties(projectId: string | null | undefined) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsPropertiesQueryOptions(projectId),
  )

  return {
    properties: data?.properties ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export async function fetchAnalyticsProperty(
  projectId: string,
  propertyId: string,
) {
  return await sdk.forProject(projectId).analytics.getProperty({ propertyId })
}

export function analyticsPropertyQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['analytics', 'property', projectId, propertyId],
    queryFn: () => fetchAnalyticsProperty(projectId!, propertyId!),
    enabled: !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsProperty(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsPropertyQueryOptions(projectId, propertyId),
  )

  return { property: data, isLoading, isFetching, error, refetch }
}

// ─── Stats ──────────────────────────────────────────────────────────────────

export async function fetchAnalyticsStats(
  projectId: string,
  propertyId: string,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  return await sdk
    .forProject(projectId)
    .analytics.getStats({ propertyId, dateRange })
}

export function analyticsStatsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  return queryOptions({
    queryKey: ['analytics', 'stats', projectId, propertyId, dateRange],
    queryFn: () => fetchAnalyticsStats(projectId!, propertyId!, dateRange),
    enabled: !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsStats(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsStatsQueryOptions(projectId, propertyId, dateRange),
  )

  return { stats: data, isLoading, isFetching, error, refetch }
}

/**
 * Stats for every property in the list view. `listProperties` does not embed
 * metrics, so the list fans out one `getStats` call per property. Each query is
 * independent, so a property whose stats fail still renders its card.
 */
export function useAnalyticsPropertiesStats(
  projectId: string | null | undefined,
  propertyIds: string[],
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  const results = useQueries({
    queries: propertyIds.map((propertyId) =>
      analyticsStatsQueryOptions(projectId, propertyId, dateRange),
    ),
  })

  const statsByPropertyId: Record<string, Models.AnalyticsMetric> = {}
  propertyIds.forEach((propertyId, index) => {
    const data = results[index]?.data
    if (data) statsByPropertyId[propertyId] = data
  })

  return {
    statsByPropertyId,
    isLoading: results.some((result) => result.isLoading),
  }
}

// ─── Events ─────────────────────────────────────────────────────────────────

export async function fetchAnalyticsEvents(
  projectId: string,
  propertyId: string,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  const response = await sdk
    .forProject(projectId)
    .analytics.listEvents({ propertyId, dateRange })

  return {
    events: response.events || [],
    total: response.total || 0,
  }
}

export function analyticsEventsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  return queryOptions({
    queryKey: ['analytics', 'events', projectId, propertyId, dateRange],
    queryFn: () => fetchAnalyticsEvents(projectId!, propertyId!, dateRange),
    enabled: !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsEvents(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsEventsQueryOptions(projectId, propertyId, dateRange),
  )

  return {
    events: data?.events ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export async function fetchAnalyticsEventMetrics(
  projectId: string,
  propertyId: string,
  eventName: string = ANALYTICS_PAGEVIEW_EVENT,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  const response = await sdk
    .forProject(projectId)
    .analytics.getEventMetrics({ propertyId, eventName, dateRange })

  return {
    points: parseAnalyticsMetricSeries(response.metrics),
    total: response.total || 0,
  }
}

export function analyticsEventMetricsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  eventName: string = ANALYTICS_PAGEVIEW_EVENT,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  return queryOptions({
    queryKey: [
      'analytics',
      'event-metrics',
      projectId,
      propertyId,
      eventName,
      dateRange,
    ],
    queryFn: () =>
      fetchAnalyticsEventMetrics(projectId!, propertyId!, eventName, dateRange),
    enabled: !!projectId && !!propertyId && !!eventName && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsEventMetrics(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  eventName: string = ANALYTICS_PAGEVIEW_EVENT,
  dateRange: AnalyticsDateRange = DEFAULT_ANALYTICS_DATE_RANGE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsEventMetricsQueryOptions(
      projectId,
      propertyId,
      eventName,
      dateRange,
    ),
  )

  return {
    points: data?.points ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

// ─── Mutations ──────────────────────────────────────────────────────────────

export type CreateAnalyticsPropertyInput = {
  propertyId?: string
  name: string
  domain?: string
  timezone?: string
}

export function useCreateAnalyticsProperty(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: CreateAnalyticsPropertyInput) => {
      if (!projectId) throw new Error('Project ID is required')

      return await sdk.forProject(projectId).analytics.createProperty({
        propertyId: input.propertyId?.trim() || ID.unique(),
        name: input.name.trim(),
        domain: input.domain?.trim() || undefined,
        timezone: input.timezone?.trim() || undefined,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['analytics', 'properties', projectId],
      })
    },
  })
}
