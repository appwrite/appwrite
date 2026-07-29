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
  keepPreviousData,
} from '@tanstack/react-query'
import { ID, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_PAGE_SIZE,
  DEFAULT_STALE_TIME,
  isClientQueryEnabled,
} from './constants'

/**
 * Date range shorthands accepted by the Analytics API (`dateRange` query param).
 * For an arbitrary window use an explicit `startAt`/`endAt` range instead.
 */
export const ANALYTICS_DATE_RANGES = ['24h', '7d', '30d', '90d'] as const

export type AnalyticsDateRange = (typeof ANALYTICS_DATE_RANGES)[number]

/**
 * An analytics window: either a shorthand the API understands directly, or an
 * explicit ISO 8601 window mapped onto `startAt`/`endAt`.
 */
export type AnalyticsRange =
  | { kind: 'shorthand'; dateRange: AnalyticsDateRange }
  | { kind: 'custom'; startAt: string; endAt: string }

export const DEFAULT_ANALYTICS_DATE_RANGE: AnalyticsDateRange = '30d'

/**
 * Default window used by both route loaders and views. Must stay in sync so the
 * prefetched query key matches what the view requests (no layout shift).
 */
export const DEFAULT_ANALYTICS_RANGE: AnalyticsRange = {
  kind: 'shorthand',
  dateRange: DEFAULT_ANALYTICS_DATE_RANGE,
}

/** Conventional event name used for the pageview time series. */
export const ANALYTICS_PAGEVIEW_EVENT = 'pageview'

export function isAnalyticsDateRange(
  value: string | undefined | null,
): value is AnalyticsDateRange {
  return !!value && (ANALYTICS_DATE_RANGES as readonly string[]).includes(value)
}

/** Stable serialization of a range, used as a query-key segment. */
export function analyticsRangeKey(range: AnalyticsRange): string {
  return range.kind === 'shorthand'
    ? range.dateRange
    : `${range.startAt}..${range.endAt}`
}

/**
 * Map a range onto the API's parameters. The backend ignores `dateRange` for
 * any bound supplied explicitly, so the two forms are kept mutually exclusive.
 */
function analyticsRangeParams(
  range: AnalyticsRange,
): { dateRange: string } | { startAt: string; endAt: string } {
  return range.kind === 'shorthand'
    ? { dateRange: range.dateRange }
    : { startAt: range.startAt, endAt: range.endAt }
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

// ─── Properties ─────────────────────────────────────────────────────────────

/**
 * Properties are paginated and searched server side via
 * `listProperties({ queries, search, total })`.
 */
export async function fetchAnalyticsProperties(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId) {
    return { properties: [] as Models.AnalyticsProperty[], total: 0 }
  }

  const response = await sdk.forProject(projectId).analytics.listProperties({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
    search: search?.trim() || undefined,
    total: true,
  })

  return {
    properties: response.properties || [],
    total: response.total || 0,
  }
}

export function analyticsPropertiesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['analytics', 'properties', projectId, page, limit, search ?? ''],
    queryFn: () => fetchAnalyticsProperties(projectId!, page, limit, search),
    enabled: !!projectId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsProperties(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsPropertiesQueryOptions(projectId, page, limit, search),
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
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  return await sdk.forProject(projectId).analytics.getStats({
    propertyId,
    ...analyticsRangeParams(range),
  })
}

export function analyticsStatsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  return queryOptions({
    queryKey: [
      'analytics',
      'stats',
      projectId,
      propertyId,
      analyticsRangeKey(range),
    ],
    queryFn: () => fetchAnalyticsStats(projectId!, propertyId!, range),
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
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsStatsQueryOptions(projectId, propertyId, range),
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
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  const results = useQueries({
    queries: propertyIds.map((propertyId) =>
      analyticsStatsQueryOptions(projectId, propertyId, range),
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
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  const response = await sdk.forProject(projectId).analytics.listEvents({
    propertyId,
    ...analyticsRangeParams(range),
  })

  return {
    events: response.events || [],
    total: response.total || 0,
  }
}

export function analyticsEventsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  return queryOptions({
    queryKey: [
      'analytics',
      'events',
      projectId,
      propertyId,
      analyticsRangeKey(range),
    ],
    queryFn: () => fetchAnalyticsEvents(projectId!, propertyId!, range),
    enabled: !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

/** How often the setup wizard asks whether the first event has landed. */
const FIRST_EVENT_POLL_INTERVAL = 5000

/**
 * Poll a freshly created property until its first event arrives, so the setup
 * wizard can confirm that tracking actually works. Polling stops as soon as an
 * event is seen, and never starts unless `enabled`.
 */
export function useAnalyticsFirstEvent(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  enabled: boolean = true,
) {
  const isEnabled =
    !!projectId && !!propertyId && enabled && isClientQueryEnabled

  const { data, isFetching } = useQuery({
    queryKey: ['analytics', 'first-event', projectId, propertyId],
    queryFn: () =>
      fetchAnalyticsEvents(projectId!, propertyId!, {
        kind: 'shorthand',
        dateRange: '24h',
      }),
    enabled: isEnabled,
    retry: false,
    staleTime: 0,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchInterval: (query) =>
      (query.state.data?.total ?? 0) > 0 ? false : FIRST_EVENT_POLL_INTERVAL,
  })

  return {
    eventReceived: (data?.total ?? 0) > 0,
    firstEventName: data?.events?.[0]?.name,
    isChecking: isFetching,
  }
}

export function useAnalyticsEvents(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsEventsQueryOptions(projectId, propertyId, range),
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
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  const response = await sdk.forProject(projectId).analytics.getEventMetrics({
    propertyId,
    eventName,
    ...analyticsRangeParams(range),
  })

  return {
    points: response.metrics || [],
    total: response.total || 0,
  }
}

export function analyticsEventMetricsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  eventName: string = ANALYTICS_PAGEVIEW_EVENT,
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  return queryOptions({
    queryKey: [
      'analytics',
      'event-metrics',
      projectId,
      propertyId,
      eventName,
      analyticsRangeKey(range),
    ],
    queryFn: () =>
      fetchAnalyticsEventMetrics(projectId!, propertyId!, eventName, range),
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
  range: AnalyticsRange = DEFAULT_ANALYTICS_RANGE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsEventMetricsQueryOptions(projectId, propertyId, eventName, range),
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

/**
 * Sparse update: only the keys present here are sent, and the backend leaves
 * every omitted attribute untouched. `false`, `''` and `[]` are meaningful
 * values, so callers must omit unchanged fields rather than passing falsy
 * placeholders.
 */
export type UpdateAnalyticsPropertyInput = {
  propertyId: string
  name?: string
  domain?: string
  timezone?: string
  enabled?: boolean
  /** Maps to the API's `xpublic` (public stats visibility). */
  xpublic?: boolean
  allowedOrigins?: string[]
}

export function useUpdateAnalyticsProperty(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: UpdateAnalyticsPropertyInput) => {
      if (!projectId) throw new Error('Project ID is required')

      return await sdk.forProject(projectId).analytics.updateProperty({
        propertyId: input.propertyId,
        ...(input.name !== undefined && { name: input.name }),
        ...(input.domain !== undefined && { domain: input.domain }),
        ...(input.timezone !== undefined && { timezone: input.timezone }),
        ...(input.enabled !== undefined && { enabled: input.enabled }),
        ...(input.xpublic !== undefined && { xpublic: input.xpublic }),
        ...(input.allowedOrigins !== undefined && {
          allowedOrigins: input.allowedOrigins,
        }),
      })
    },
    onSuccess: async (property) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['analytics', 'property', projectId, property.$id],
        }),
        queryClient.refetchQueries({
          queryKey: ['analytics', 'properties', projectId],
        }),
      ])
    },
  })
}

export function useDeleteAnalyticsProperty(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (propertyId: string) => {
      if (!projectId) throw new Error('Project ID is required')

      return await sdk
        .forProject(projectId)
        .analytics.deleteProperty({ propertyId })
    },
    onSuccess: async (_result, propertyId) => {
      queryClient.removeQueries({
        queryKey: ['analytics', 'property', projectId, propertyId],
      })
      await queryClient.refetchQueries({
        queryKey: ['analytics', 'properties', projectId],
      })
    },
  })
}
