/**
 * Analytics goals and conversions.
 *
 * TODO(goals): the Console SDK does not carry the goal methods, models or the
 * `goalId`/`goal` metrics params until a preview built from Cloud main does.
 * Until then every call goes through `client.call`; swap them for
 * `sdk.forProject(projectId).analytics.*Goal(s)` once that pin lands.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  analyticsFilterQueries,
  analyticsFiltersKey,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import { analyticsRangeKey, type AnalyticsRange } from './analytics'
import { DEFAULT_STALE_TIME, isClientQueryEnabled } from './constants'

export type AnalyticsGoalType = 'event' | 'page'

export type AnalyticsGoal = {
  $id: string
  $createdAt: string
  $updatedAt: string
  propertyId: string
  name: string
  type: AnalyticsGoalType
  eventName: string
  pagePath: string
  queries: string[]
}

/** A metrics row read with a goal: conversion columns, plus `goalId` on `goal` rows. */
export type AnalyticsConversionMetric = Models.AnalyticsMetric & {
  goalId?: string | null
  conversions?: number | null
  conversionEvents?: number | null
  conversionRate?: number | null
}

/** The API's per-request page cap; a property holds at most 1,000 goals. */
const GOALS_PAGE_SIZE = 100

const NO_FILTERS: readonly AnalyticsFilter[] = []

function callAnalytics<T>(
  projectId: string,
  method: 'get' | 'post' | 'patch' | 'delete',
  path: string,
  params: Record<string, unknown> = {},
): Promise<T> {
  const { client } = sdk.forProject(projectId)
  return client.call(
    method,
    new URL(client.config.endpoint + path),
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    params,
  ) as Promise<T>
}

const goalsPath = (propertyId: string) =>
  `/analytics/properties/${propertyId}/goals`

export async function fetchAnalyticsGoals(
  projectId: string,
  propertyId: string,
) {
  const goals: AnalyticsGoal[] = []
  for (;;) {
    const response = await callAnalytics<{
      total: number
      goals: AnalyticsGoal[]
    }>(projectId, 'get', goalsPath(propertyId), {
      queries: [
        Query.orderAsc('name'),
        Query.limit(GOALS_PAGE_SIZE),
        Query.offset(goals.length),
      ],
    })
    goals.push(...response.goals)
    if (
      response.goals.length < GOALS_PAGE_SIZE ||
      goals.length >= response.total
    ) {
      return goals
    }
  }
}

export function analyticsGoalsQueryOptions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['analytics', 'goals', projectId, propertyId],
    queryFn: () => fetchAnalyticsGoals(projectId!, propertyId!),
    enabled: !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
  })
}

export function useAnalyticsGoals(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  const { data, isLoading, error } = useQuery(
    analyticsGoalsQueryOptions(projectId, propertyId),
  )
  return { goals: data ?? [], isLoading, error }
}

/**
 * Metrics read with conversion params. `dimension: 'goal'` returns one row per
 * goal; `goalId` adds the conversion columns to any other shape.
 */
export async function fetchAnalyticsConversions(
  projectId: string,
  propertyId: string,
  range: AnalyticsRange,
  params: {
    goalId?: string
    dimension?: string
    limit?: number
    propKey?: string
  },
  filters: readonly AnalyticsFilter[] = NO_FILTERS,
) {
  // client.call sends an undefined param as the literal "undefined", so omit it.
  const queries = analyticsFilterQueries(filters)
  const response = await callAnalytics<{
    metrics: AnalyticsConversionMetric[]
  }>(projectId, 'get', `/analytics/properties/${propertyId}/metrics`, {
    startAt: range.startAt,
    endAt: range.endAt,
    ...(queries ? { queries } : {}),
    ...(params.goalId ? { goalId: params.goalId } : {}),
    ...(params.dimension ? { dimensions: [params.dimension] } : {}),
    ...(params.propKey ? { propKey: params.propKey } : {}),
    ...(params.limit ? { limit: params.limit } : {}),
  })
  return response.metrics ?? []
}

export function useAnalyticsConversions(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
  range: AnalyticsRange,
  params: {
    goalId?: string
    dimension?: string
    limit?: number
    propKey?: string
  },
  filters: readonly AnalyticsFilter[] = NO_FILTERS,
  enabled = true,
) {
  const { data, isLoading, error } = useQuery({
    queryKey: [
      'analytics',
      'conversions',
      projectId,
      propertyId,
      analyticsRangeKey(range),
      params.goalId ?? null,
      params.dimension ?? null,
      params.limit ?? null,
      params.propKey ?? null,
      ...analyticsFiltersKey(filters),
    ],
    queryFn: () =>
      fetchAnalyticsConversions(
        projectId!,
        propertyId!,
        range,
        params,
        filters,
      ),
    enabled: enabled && !!projectId && !!propertyId && isClientQueryEnabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
  })
  return { metrics: data ?? [], isLoading, error }
}

export type SaveAnalyticsGoalInput = {
  /** Omit to create a goal. */
  goalId?: string
  name: string
  type: AnalyticsGoalType
  eventName: string
  pagePath: string
  queries: string[]
}

export function useSaveAnalyticsGoal(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (input: SaveAnalyticsGoalInput) => {
      if (!projectId || !propertyId) throw new Error('Property is required')
      const body = {
        name: input.name.trim(),
        type: input.type,
        eventName: input.type === 'event' ? input.eventName.trim() : '',
        pagePath: input.type === 'page' ? input.pagePath.trim() : '',
        queries: input.queries,
      }
      return input.goalId
        ? await callAnalytics<AnalyticsGoal>(
            projectId,
            'patch',
            `${goalsPath(propertyId)}/${input.goalId}`,
            body,
          )
        : await callAnalytics<AnalyticsGoal>(
            projectId,
            'post',
            goalsPath(propertyId),
            {
              ...body,
              goalId: ID.unique(),
            },
          )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['analytics', 'goals', projectId, propertyId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['analytics', 'conversions', projectId, propertyId],
      })
    },
  })
}

export function useDeleteAnalyticsGoal(
  projectId: string | null | undefined,
  propertyId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (goalId: string) => {
      if (!projectId || !propertyId) throw new Error('Property is required')
      await callAnalytics(
        projectId,
        'delete',
        `${goalsPath(propertyId)}/${goalId}`,
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['analytics', 'goals', projectId, propertyId],
      })
      await queryClient.invalidateQueries({
        queryKey: ['analytics', 'conversions', projectId, propertyId],
      })
    },
  })
}
