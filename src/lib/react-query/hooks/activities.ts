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

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

export interface FetchActivitiesParams {
  projectId: string
  page?: number
  limit?: number
  /** Optional filter by `event` value (e.g. "users.create"). Matches via Query.equal. */
  event?: string
  /** Optional filter by resource type (e.g. "users", "files"). */
  resourceType?: string
  /** Optional filter by user that triggered the event. */
  userId?: string
  /** Optional cutoff timestamp (ISO 8601). Only events at or after will be returned. */
  since?: string
}

export interface ActivitiesResult {
  events: Models.ActivityEvent[]
  total: number
}

/**
 * Fetches a page of activity events for a project.
 *
 * Reused in both the route loader (via `activitiesQueryOptions`) and the
 * `useProjectActivities` hook so cache keys match exactly.
 */
export async function fetchProjectActivities({
  projectId,
  page = 0,
  limit = ACTIVITY_DEFAULT_PAGE_SIZE,
  event,
  resourceType,
  userId,
  since,
}: FetchActivitiesParams): Promise<ActivitiesResult> {
  if (!projectId) {
    return { events: [], total: 0 }
  }

  const queries: string[] = [
    Query.orderDesc('time'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  if (event) queries.push(Query.equal('event', event))
  if (resourceType) queries.push(Query.equal('resourceType', resourceType))
  if (userId) queries.push(Query.equal('userId', userId))
  if (since) queries.push(Query.greaterThanEqual('time', since))

  // The SDK type definition for `listEvents` declares `queries: string`, but
  // the runtime accepts the same `string[]` shape used by every other list
  // endpoint and serializes it as `queries[]=...`. Cast to satisfy the type.
  const response = await sdk
    .forProject(projectId)
    .activities.listEvents({ queries: queries as unknown as string })

  return {
    events: response.events ?? [],
    total: response.total ?? 0,
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

export function activitiesQueryOptions(params: {
  projectId: string | null | undefined
  page?: number
  limit?: number
  event?: string
  resourceType?: string
  userId?: string
  since?: string
}) {
  const {
    projectId,
    page = 0,
    limit = ACTIVITY_DEFAULT_PAGE_SIZE,
    event,
    resourceType,
    userId,
    since,
  } = params

  return queryOptions({
    queryKey: [
      'activities',
      'project',
      projectId,
      page,
      limit,
      event ?? null,
      resourceType ?? null,
      userId ?? null,
      since ?? null,
    ],
    queryFn: () =>
      fetchProjectActivities({
        projectId: projectId!,
        page,
        limit,
        event,
        resourceType,
        userId,
        since,
      }),
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
  page?: number
  limit?: number
  event?: string
  resourceType?: string
  userId?: string
  since?: string
}) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    activitiesQueryOptions(params),
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
