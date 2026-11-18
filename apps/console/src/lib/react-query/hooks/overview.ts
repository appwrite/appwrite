import { OVERVIEW_RESOURCE_ITEM_LIMIT } from '@/lib/overview-resources'
import { consoleDatabasesQueryOptions } from './databases'
import { functionsQueryOptions } from './functions'
import { topicsQueryOptions } from './messaging'
import { sitesQueryOptions } from './sites'
import { bucketsQueryOptions } from './storage'
import { usersQueryOptions } from './users'

// The dashboard lists a few items per service, so these keys stay apart from
// the service pages. Mutations refetch by the `[resource, 'project', id]`
// prefix, which covers them too.

/**
 * The resource cards render placeholders while they load, so a slow list
 * never holds the fullscreen loader. The service lists skip refetching on
 * mount; the dashboard refetches a stale list so a new resource shows up.
 */
const OVERVIEW_LIST_OPTIONS = {
  meta: { skipInitialLoader: true },
  refetchOnMount: true,
} as const

export function overviewDatabasesQueryOptions(projectId: string) {
  return {
    ...consoleDatabasesQueryOptions(
      projectId,
      0,
      OVERVIEW_RESOURCE_ITEM_LIMIT,
      undefined,
      undefined,
    ),
    ...OVERVIEW_LIST_OPTIONS,
  }
}

/** Recently updated first, so a site being deployed is at the top. */
export function overviewSitesQueryOptions(projectId: string) {
  return {
    ...sitesQueryOptions(
      projectId,
      0,
      OVERVIEW_RESOURCE_ITEM_LIMIT,
      undefined,
      undefined,
      '$updatedAt',
      'desc',
    ),
    ...OVERVIEW_LIST_OPTIONS,
  }
}

/** Recently updated first, so a function being deployed is at the top. */
export function overviewFunctionsQueryOptions(projectId: string) {
  return {
    ...functionsQueryOptions(
      projectId,
      0,
      OVERVIEW_RESOURCE_ITEM_LIMIT,
      undefined,
      undefined,
      '$updatedAt',
      'desc',
    ),
    ...OVERVIEW_LIST_OPTIONS,
  }
}

export function overviewBucketsQueryOptions(projectId: string) {
  return {
    ...bucketsQueryOptions(projectId, 0, OVERVIEW_RESOURCE_ITEM_LIMIT),
    ...OVERVIEW_LIST_OPTIONS,
  }
}

export function overviewUsersQueryOptions(projectId: string) {
  return {
    ...usersQueryOptions(projectId, 0, OVERVIEW_RESOURCE_ITEM_LIMIT),
    ...OVERVIEW_LIST_OPTIONS,
  }
}

export function overviewTopicsQueryOptions(projectId: string) {
  return {
    ...topicsQueryOptions(projectId, 0, OVERVIEW_RESOURCE_ITEM_LIMIT),
    ...OVERVIEW_LIST_OPTIONS,
  }
}
