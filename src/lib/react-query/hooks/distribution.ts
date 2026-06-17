/**
 * React Query hooks for Distribution (App Store Pipeline)
 *
 * POC: backed by an in-memory mock data layer until the `/v1/distribution`
 * API and SDK methods land. Query function and `queryOptions` shapes mirror the
 * real hooks (sites, functions) so they can be swapped for live fetchers later.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import {
  fetchDistributionApps as mockFetchDistributionApps,
  fetchDistributionApp as mockFetchDistributionApp,
  fetchDistributionBuilds as mockFetchDistributionBuilds,
  fetchDistributionSubmissions as mockFetchDistributionSubmissions,
  type DistributionApp,
  type DistributionAppList,
  type DistributionBuildList,
  type DistributionSubmissionList,
} from '@/lib/distribution/distribution-mock'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'

export type {
  DistributionApp,
  DistributionBuild,
  DistributionSubmission,
  DistributionPlatform,
  DistributionFramework,
  DistributionArtifactType,
  DistributionBuildStatus,
  DistributionProvider,
  DistributionSubmissionStatus,
} from '@/lib/distribution/distribution-mock'

export const DISTRIBUTION_DEFAULT_SORT_BY = '$createdAt'
export const DISTRIBUTION_DEFAULT_SORT_ORDER = 'desc' as const

export async function fetchDistributionApps(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<DistributionAppList> {
  if (!projectId) {
    return { apps: [], total: 0 }
  }
  return mockFetchDistributionApps(projectId, page, limit, search)
}

export async function fetchDistributionApp(
  projectId: string,
  appId: string,
): Promise<DistributionApp> {
  if (!projectId || !appId) {
    throw new Error('Project ID and App ID are required')
  }
  return mockFetchDistributionApp(projectId, appId)
}

export async function fetchDistributionBuilds(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<DistributionBuildList> {
  if (!projectId || !appId) {
    return { builds: [], total: 0 }
  }
  return mockFetchDistributionBuilds(projectId, appId, page, limit)
}

export async function fetchDistributionSubmissions(
  projectId: string,
  appId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<DistributionSubmissionList> {
  if (!projectId || !appId) {
    return { submissions: [], total: 0 }
  }
  return mockFetchDistributionSubmissions(projectId, appId, page, limit)
}

export function distributionAppsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['distribution-apps', 'project', projectId, page, limit, search],
    queryFn: () => fetchDistributionApps(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function distributionAppQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['distribution-app', 'project', projectId, appId],
    queryFn: () => fetchDistributionApp(projectId!, appId!),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function distributionBuildsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['distribution-builds', 'project', projectId, appId, page, limit],
    queryFn: () => fetchDistributionBuilds(projectId!, appId!, page, limit),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function distributionSubmissionsQueryOptions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: [
      'distribution-submissions',
      'project',
      projectId,
      appId,
      page,
      limit,
    ],
    queryFn: () =>
      fetchDistributionSubmissions(projectId!, appId!, page, limit),
    enabled: !!projectId && !!appId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}

export function useDistributionApps(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionAppsQueryOptions(projectId, page, limit, search),
  )
  return {
    apps: data?.apps ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useDistributionApp(
  projectId: string | null | undefined,
  appId: string | null | undefined,
) {
  return useQuery(distributionAppQueryOptions(projectId, appId))
}

export function useDistributionBuilds(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionBuildsQueryOptions(projectId, appId, page, limit),
  )
  return {
    builds: data?.builds ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useDistributionSubmissions(
  projectId: string | null | undefined,
  appId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    distributionSubmissionsQueryOptions(projectId, appId, page, limit),
  )
  return {
    submissions: data?.submissions ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}
