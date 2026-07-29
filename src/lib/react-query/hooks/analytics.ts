/**
 * React Query hooks for Analytics (web analytics properties, stats and events)
 *
 * The Analytics API is project-scoped (`sdk.forProject(projectId).analytics`).
 * All list/detail data is fetched through exported functions so route loaders and
 * hooks share the exact same query configuration (see AGENTS.md "QueryOptions Pattern").
 */

import {
  queryOptions,
  useIsFetching,
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
  keepPreviousData,
  type Query as CachedQuery,
} from '@tanstack/react-query'
import { useCallback } from 'react'
import { endOfDay, startOfDay, subDays, subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { normalizeUsageDateRangeSelection } from '@/lib/usage/usage-date-range'
import {
  AnalyticsDimension,
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
  newVisitors: 0,
  returningVisitors: 0,
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
  range: AnalyticsRange = getDefaultAnalyticsRange(),
) {
  return await sdk.forProject(projectId).analytics.getStats({
    propertyId,
    startAt: range.startAt,
    endAt: range.endAt,
  })
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
 * metrics, so the list fans out one `getStats` call per property. Each query is
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

export async function fetchAnalyticsEvents(
  projectId: string,
  propertyId: string,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
) {
  const response = await sdk.forProject(projectId).analytics.listEvents({
    propertyId,
    startAt: range.startAt,
    endAt: range.endAt,
  })

  return {
    events: response.events || [],
    total: response.total || 0,
  }
}

export function analyticsEventsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
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
   * Derived from the queries' own fetch state rather than a boolean we set
   * ourselves.
   *
   * The previous version awaited `refetchQueries` and cleared a flag in
   * `finally`. That promise only resolves once *every* matched query settles,
   * so a single request that never settles pinned the flag on forever, and
   * `RefreshButton` disables itself while refreshing, leaving the control
   * spinning and unclickable. Measured with query-core: one hanging queryFn
   * among three healthy ones leaves `refetchQueries` pending indefinitely while
   * `isFetching` correctly reports 1.
   *
   * Reading `isFetching` cannot desync from reality: it falls to 0 exactly when
   * the requests finish, and it stays 0 when a refresh matches nothing, which
   * makes "nothing happened" visible instead of faking a spin.
   */
  const fetchingCount = useIsFetching({ predicate: matchesProperty })

  const refresh = useCallback(() => {
    if (!projectId || !propertyId) return
    // Fire and forget: the spinner follows real fetch activity, so there is no
    // promise to await and no flag to reset.
    void queryClient
      .refetchQueries({
        // `type: 'all'` also refetches queries whose panel is not currently
        // mounted (an inactive tab), so switching tabs after a refresh shows
        // fresh data instead of a stale cache entry.
        type: 'all',
        predicate: matchesProperty,
      })
      .catch(() => {
        // Failures surface through each query's own error state.
      })
  }, [queryClient, matchesProperty, projectId, propertyId])

  return { refresh, isRefreshing: fetchingCount > 0 }
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
    firstEventName: data?.events?.[0]?.name,
    isChecking: isFetching,
  }
}

export function useAnalyticsEvents(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange = getDefaultAnalyticsRange(),
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
  range: AnalyticsRange = getDefaultAnalyticsRange(),
) {
  const response = await sdk.forProject(projectId).analytics.getEventMetrics({
    propertyId,
    eventName,
    startAt: range.startAt,
    endAt: range.endAt,
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
  const response = await sdk.forProject(projectId).analytics.getBreakdown({
    propertyId,
    dimension,
    startAt: range.startAt,
    endAt: range.endAt,
    limit,
  })

  return {
    breakdown: response.breakdown || [],
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
