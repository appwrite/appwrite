/**
 * React Query hooks for project Activity events
 *
 * Wraps the Activities service from `@appwrite.io/console`. Activity events are
 * project-scoped audit logs of resource changes (create/update/delete) and
 * authentication events.
 */

import { useQuery, queryOptions, keepPreviousData } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { ACTIVITY_DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'
import {
  queryParamToMap,
  getActivityFilterQueryParts,
} from '@/lib/table-filters'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

export interface FetchActivitiesParams {
  projectId: string
  limit?: number
  /**
   * Next page: pass the last row `$id` from the previous page (`Query.cursorAfter`).
   * Mutually exclusive with `cursorBefore`.
   */
  cursorAfter?: string | null
  /**
   * Previous page: pass the first row `$id` from the current page (`Query.cursorBefore`).
   * Mutually exclusive with `cursorAfter`.
   */
  cursorBefore?: string | null
  /** Lower bound for `time` (plan retention merged with user filters on `time`). */
  mergedSince: string
  /** Optional upper bound for `time` (ISO 8601). */
  until?: string | null
  /** Additional Query strings from the URL filter map (resource type, userId, event, …). */
  extraQueries?: string[]
}

export interface ActivitiesResult {
  events: Models.ActivityEvent[]
  /** True when the API may have more rows after this page (`total` was not requested). */
  hasMore: boolean
}

/**
 * Fetches a page of activity events for a project.
 *
 * Uses [cursor pagination](https://appwrite.io/docs/products/databases/pagination)
 * (`Query.cursorAfter` / `Query.cursorBefore`) with `orderDesc('time')`, not offset.
 * Reused in both the route loader (via `activitiesQueryOptions`) and the
 * `useProjectActivities` hook so cache keys match exactly.
 */
export async function fetchProjectActivities({
  projectId,
  limit = ACTIVITY_DEFAULT_PAGE_SIZE,
  cursorAfter,
  cursorBefore,
  mergedSince,
  until,
  extraQueries,
}: FetchActivitiesParams): Promise<ActivitiesResult> {
  if (!projectId) {
    return { events: [], hasMore: false }
  }

  const queries: string[] = [Query.orderDesc('time'), Query.limit(limit)]

  if (cursorBefore) {
    queries.push(Query.cursorBefore(cursorBefore))
  } else if (cursorAfter) {
    queries.push(Query.cursorAfter(cursorAfter))
  }

  queries.push(Query.greaterThanEqual('time', mergedSince))
  if (until) {
    queries.push(Query.lessThanEqual('time', until))
  }

  for (const q of extraQueries ?? []) {
    queries.push(q)
  }

  // The SDK type definition for `listEvents` declares `queries: string`, but
  // the runtime accepts the same `string[]` shape used by every other list
  // endpoint and serializes it as `queries[]=...`. Cast to satisfy the type.
  const projectSdk = sdk.forProject(projectId)
  const endpoint = projectSdk.client.config.endpoint as string
  const uri = new URL(`${endpoint}/activities/events`)
  const response = (await projectSdk.client.call('get', uri, {}, {
    queries: queries as unknown as string,
    total: false,
  })) as Models.ActivityEventList

  const events = response.events ?? []

  return {
    events,
    hasMore: events.length === limit,
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

export function activitiesQueryOptions(params: {
  projectId: string | null | undefined
  limit?: number
  cursorAfter?: string | null
  cursorBefore?: string | null
  /** Plan retention floor (ISO); merged with URL `time` filters inside the queryFn. */
  planSinceIso: string
  /** Raw URL `query` param (encoded filter keys), or null when unset. */
  filterQueryKey: string | null
}) {
  const {
    projectId,
    limit = ACTIVITY_DEFAULT_PAGE_SIZE,
    cursorAfter = null,
    cursorBefore = null,
    planSinceIso,
    filterQueryKey,
  } = params

  return queryOptions({
    queryKey: [
      'activities',
      'project',
      projectId,
      limit,
      cursorAfter ?? null,
      cursorBefore ?? null,
      planSinceIso,
      filterQueryKey ?? null,
    ],
    queryFn: () => {
      const filterMap = queryParamToMap(filterQueryKey)
      const { mergedSince, until, extraQueries } = getActivityFilterQueryParts(
        filterMap,
        planSinceIso,
      )
      return fetchProjectActivities({
        projectId: projectId!,
        limit,
        cursorAfter,
        cursorBefore,
        mergedSince,
        until,
        extraQueries,
      })
    },
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch a page of project activity events.
 */
export function useProjectActivities(params: {
  projectId: string | null | undefined
  limit?: number
  cursorAfter?: string | null
  cursorBefore?: string | null
  planSinceIso: string
  filterQueryKey: string | null
}) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    activitiesQueryOptions(params),
  )

  return {
    events: data?.events ?? [],
    hasMore: data?.hasMore ?? false,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single activity event by ID.
 */
export function useProjectActivity(
  projectId: string | null | undefined,
  eventId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['activity', 'project', projectId, eventId],
    queryFn: async () => {
      if (!projectId || !eventId) {
        throw new Error('Project ID and Event ID are required')
      }
      return await sdk.forProject(projectId).activities.getEvent({ eventId })
    },
    enabled: !!projectId && !!eventId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    event: data ?? null,
    isLoading,
    error,
    refetch,
  }
}
