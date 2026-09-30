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
  type Query as CachedQuery,
} from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { endOfDay, startOfDay, subDays, subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { normalizeUsageDateRangeSelection } from '@/lib/usage/usage-date-range'
import {
  AnalyticsDimension,
  AnalyticsInterval,
  ID,
  Query,
  type Models,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_PAGE_SIZE,
  DEFAULT_STALE_TIME,
  isClientQueryEnabled,
} from './constants'

/**
 * An analytics window, always concrete ISO 8601 bounds mapped onto the API's
 * `startAt` / `endAt`.
 *
 * The shorthand form (`dateRange: '30d'`) was removed when the page adopted the
 * shared `DateRangePicker`: every selection now produces real bounds, so a
 * shorthand branch would have been dead code that still looked live. The API
 * still accepts `dateRange`; nothing in the console needs it.
 */
export type AnalyticsRange = { startAt: string; endAt: string }

/**
 * How long an analytics read may take before its query is failed.
 *
 * The SDK sets no request timeout, so a stalled connection leaves the query
 * fetching forever. `useRefreshAnalyticsProperty` waits for a refresh to settle
 * before clearing its flag, so without a bound one stalled read would leave the
 * refresh control spinning and - since `RefreshButton` disables itself while
 * refreshing - unclickable. Verified with query-core: one queryFn that never
 * resolves keeps `refetchQueries` pending indefinitely while every other query
 * settles normally.
 *
 * With a timeout the query fails instead, the panel shows its own error, and the
 * refresh finishes.
 *
 * Deliberately generous. This is a backstop against a request that will never
 * arrive, not a latency budget - a read that is merely slow should still be
 * allowed to land. It was 20s, which turned out to sit right on top of real
 * breakdown latency against staging (four panels measured at 20.3s), so it was
 * rejecting queries whose responses then arrived a fraction of a second later
 * with a 200. Raising it costs nothing: a genuinely dead request still settles,
 * just later, and nothing else keys off this value.
 */
const READ_TIMEOUT_MS = 60_000

/**
 * Reject if `request` has not settled within `READ_TIMEOUT_MS`.
 *
 * Note this does not abort the underlying HTTP request - the console SDK takes
 * no AbortSignal - it only stops the query from waiting on it. A late response
 * is ignored, since React Query has already settled the query.
 */
async function withReadTimeout<T>(
  operation: string,
  request: Promise<T>,
): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined

  try {
    return await Promise.race([
      request,
      new Promise<never>((_resolve, reject) => {
        timer = setTimeout(
          () =>
            reject(
              new Error(
                `Analytics ${operation} timed out after ${READ_TIMEOUT_MS / 1000}s`,
              ),
            ),
          READ_TIMEOUT_MS,
        )
      }),
    ])
  } finally {
    clearTimeout(timer)
  }
}

/** Rows requested per breakdown panel; panels show fewer until expanded. */
export const ANALYTICS_BREAKDOWN_LIMIT = 30

/** Conventional event name used for the pageview time series. */
export const ANALYTICS_PAGEVIEW_EVENT = 'pageview'

/**
 * Default picker selection: the last 30 calendar days, inclusive of today.
 *
 * Quantised to day boundaries on purpose. Route loaders and views both call
 * this, and a rolling `to: now` would differ by milliseconds between the two,
 * producing different query keys and an immediate refetch on first paint.
 */
export function getDefaultAnalyticsDateRange(): DateRange {
  const now = new Date()
  return { from: startOfDay(subDays(now, 29)), to: endOfDay(now) }
}

/**
 * Picker selection to API bounds.
 *
 * Returns `undefined` while a selection is incomplete: DayPicker reports
 * `{ from }` with no `to` after the first click, and committing then would fire
 * a request for a half-chosen range.
 *
 * `normalizeUsageDateRangeSelection` handles the end-of-day boundary the same
 * way Usage does: DayPicker returns local midnight for both ends, so a
 * date-only span is expanded to `startOfDay(from) .. endOfDay(to)`. Passing
 * `to` verbatim would exclude the final day and show a range one day short.
 */
export function toAnalyticsRange(
  dateRange: DateRange | undefined,
): AnalyticsRange | undefined {
  if (!dateRange?.from || !dateRange?.to) return undefined
  const normalized = normalizeUsageDateRangeSelection(dateRange)
  if (!normalized?.from || !normalized?.to) return undefined
  return {
    startAt: normalized.from.toISOString(),
    endAt: normalized.to.toISOString(),
  }
}

/** Default window shared by route loaders and views so their keys match. */
export function getDefaultAnalyticsRange(): AnalyticsRange {
  const range = toAnalyticsRange(getDefaultAnalyticsDateRange())
  if (!range) throw new Error('Default analytics range must be complete')
  return range
}

/** Stable serialization of a range, used as a query-key segment. */
export function analyticsRangeKey(range: AnalyticsRange): string {
  return `${range.startAt}..${range.endAt}`
}

/** Zero-filled metric used while loading or when a property has no data yet. */
export const EMPTY_ANALYTICS_METRIC: Models.AnalyticsMetric = {
  visitors: 0,
  sessions: 0,
  pageviews: 0,
  events: 0,
  visits: 0,
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

  const response = await withReadTimeout(
    'listProperties',
    sdk.forProject(projectId).analytics.listProperties({
      queries: [
        Query.orderDesc('$createdAt'),
        Query.limit(limit),
        Query.offset(page * limit),
      ],
      search: search?.trim() || undefined,
      total: true,
    }),
  )

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
  return await withReadTimeout(
    'getProperty',
    sdk.forProject(projectId).analytics.getProperty({ propertyId }),
  )
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

/**
 * Property-wide aggregate: no dimensions and no interval, which is the only
 * shape the API populates `bounceRate`, `visitDuration`, `viewsPerVisit`,
 * `scrollDepth` and `engagementTime` on. It is always a single row.
 */
export async function fetchAnalyticsStats(
  projectId: string,
  propertyId: string,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
) {
  const response = await withReadTimeout(
    'stats',
    sdk.forProject(projectId).analytics.listMetrics({
      propertyId,
      startAt: range.startAt,
      endAt: range.endAt,
    }),
  )

  return response.metrics?.[0] ?? EMPTY_ANALYTICS_METRIC
}

export function analyticsStatsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
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
  range: AnalyticsRange = getDefaultAnalyticsRange(),
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsStatsQueryOptions(projectId, propertyId, range),
  )

  return { stats: data, isLoading, isFetching, error, refetch }
}

/**
 * Stats for every property in the list view. `listProperties` does not embed
 * metrics, so the list fans out one stats read per property. Each query is
 * independent, so a property whose stats fail still renders its card.
 */
export function useAnalyticsPropertiesStats(
  projectId: string | null | undefined,
  propertyIds: string[],
  range: AnalyticsRange = getDefaultAnalyticsRange(),
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

/** Distinct event names ranked by visitors: a breakdown on `eventName`. */
export async function fetchAnalyticsEvents(
  projectId: string,
  propertyId: string,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
  limit: number = ANALYTICS_BREAKDOWN_LIMIT,
) {
  const response = await withReadTimeout(
    'events',
    sdk.forProject(projectId).analytics.listMetrics({
      propertyId,
      dimensions: [AnalyticsDimension.EventName],
      startAt: range.startAt,
      endAt: range.endAt,
      limit,
    }),
  )

  return {
    events: response.metrics || [],
    total: response.total || 0,
  }
}

export function analyticsEventsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
  limit: number = ANALYTICS_BREAKDOWN_LIMIT,
) {
  return queryOptions({
    queryKey: [
      'analytics',
      'events',
      projectId,
      propertyId,
      analyticsRangeKey(range),
      limit,
    ],
    queryFn: () => fetchAnalyticsEvents(projectId!, propertyId!, range, limit),
    enabled: !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Refetch every analytics query belonging to one property: stats, events, the
 * event series, breakdowns and the property document. Matching on the key
 * prefix plus the property ID means panels added later are covered without
 * having to extend a hardcoded key list.
 */
export function useRefreshAnalyticsProperty(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  const matchesProperty = useCallback(
    (query: CachedQuery) => {
      if (!projectId || !propertyId) return false
      const [scope, resource] = query.queryKey
      if (scope !== 'analytics') return false
      // The setup wizard's first-event probe drives itself on a
      // refetchInterval, so it is never part of a manual refresh. It is not
      // mounted on this page anyway; excluding it keeps that true if it ever is.
      if (resource === 'first-event') return false
      return (
        query.queryKey.includes(projectId) &&
        query.queryKey.includes(propertyId)
      )
    },
    [projectId, propertyId],
  )

  /**
   * A flag we own, set on click and cleared when the work settles - the same
   * shape `RefreshControls` uses for usage and the Postgres pages.
   *
   * An earlier version derived this from `useIsFetching` instead. That reports
   * fetch activity accurately, but it changes on its own between clicks, so the
   * value `RefreshButton` sees does not simply go true-then-false per press, and
   * the button's spin only stops when it observes that transition. Matching the
   * pattern the other pages already use keeps that contract.
   *
   * Awaiting the refresh is only safe because `READ_TIMEOUT_MS` bounds every
   * read: this flag waits for all of them, so one request that never settled
   * would pin it on forever.
   */
  const [isRefreshing, setIsRefreshing] = useState(false)

  const refresh = useCallback(async () => {
    if (!projectId || !propertyId) return

    setIsRefreshing(true)
    try {
      // Two calls, because neither covers every query on the page (both
      // measured against query-core):
      //
      // `refetchQueries` with `type: 'all'` refetches what is on screen plus
      // queries whose panel is unmounted but still cached. It does NOT touch a
      // query whose observer is mounted-but-disabled, which is exactly what a
      // dimension panel's hidden tabs are - so on its own it left a hidden tab
      // showing pre-refresh numbers for as long as its cache entry lived.
      //
      // `invalidateQueries` marks those stale so they refetch when their tab is
      // shown. `refetchType: 'none'` because the call above already refetched
      // everything active; without it the visible panels would fetch twice.
      //
      // allSettled, so one failing query still clears the flag - failures
      // surface through each query's own error state.
      await Promise.allSettled([
        queryClient.refetchQueries({ type: 'all', predicate: matchesProperty }),
        queryClient.invalidateQueries({
          predicate: matchesProperty,
          refetchType: 'none',
        }),
      ])
    } finally {
      setIsRefreshing(false)
    }
  }, [queryClient, matchesProperty, projectId, propertyId])

  return { refresh, isRefreshing }
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
        startAt: subHours(new Date(), 24).toISOString(),
        endAt: new Date().toISOString(),
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
    firstEventName: data?.events?.[0]?.value,
    isChecking: isFetching,
  }
}

export function useAnalyticsEvents(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
  limit: number = ANALYTICS_BREAKDOWN_LIMIT,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsEventsQueryOptions(projectId, propertyId, range, limit),
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
  range: AnalyticsRange = getDefaultAnalyticsRange(),
) {
  const response = await withReadTimeout(
    'event metrics',
    sdk.forProject(projectId).analytics.listMetrics({
      propertyId,
      interval: AnalyticsInterval.OneDay,
      queries: [Query.equal('eventName', [eventName])],
      startAt: range.startAt,
      endAt: range.endAt,
    }),
  )

  return {
    points: response.metrics || [],
    total: response.total || 0,
  }
}

export function analyticsEventMetricsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  eventName: string = ANALYTICS_PAGEVIEW_EVENT,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
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
  range: AnalyticsRange = getDefaultAnalyticsRange(),
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

// ─── Breakdowns ─────────────────────────────────────────────────────────────

/** Ranked values for one dimension, already sorted by visitors desc. */
export async function fetchAnalyticsBreakdown(
  projectId: string,
  propertyId: string,
  dimension: AnalyticsDimension,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
  limit: number = ANALYTICS_BREAKDOWN_LIMIT,
) {
  const response = await withReadTimeout(
    'breakdown',
    sdk.forProject(projectId).analytics.listMetrics({
      propertyId,
      dimensions: [dimension],
      startAt: range.startAt,
      endAt: range.endAt,
      limit,
    }),
  )

  return {
    breakdown: response.metrics || [],
    total: response.total || 0,
  }
}

export function analyticsBreakdownQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  dimension: AnalyticsDimension,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
  limit: number = ANALYTICS_BREAKDOWN_LIMIT,
  enabled: boolean = true,
) {
  return queryOptions({
    queryKey: [
      'analytics',
      'breakdown',
      projectId,
      propertyId,
      dimension,
      analyticsRangeKey(range),
      limit,
    ],
    queryFn: () =>
      fetchAnalyticsBreakdown(projectId!, propertyId!, dimension, range, limit),
    // Panels only request the dimension of their visible tab, so 21 dimensions
    // never fan out into 21 requests on mount.
    enabled: enabled && !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && propertyId ? 5 * 60 * 1000 : 0,
  })
}

export function useAnalyticsBreakdown(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  dimension: AnalyticsDimension,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
  limit: number = ANALYTICS_BREAKDOWN_LIMIT,
  enabled: boolean = true,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    analyticsBreakdownQueryOptions(
      projectId,
      propertyId,
      dimension,
      range,
      limit,
      enabled,
    ),
  )

  return {
    breakdown: data?.breakdown ?? [],
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
