/**
 * React Query hooks for Storage
 *
 * Handles buckets, files, and file tokens.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch buckets for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated buckets with total count
 */
export async function fetchProjectBuckets(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<Models.BucketList> {
  if (!projectId) {
    return { buckets: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.storage.listBuckets({
    queries,
    search: search?.trim() || undefined,
  })

  return {
    buckets: response.buckets || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single bucket by ID
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchBucket(
  projectId: string,
  bucketId: string,
): Promise<Models.Bucket> {
  if (!projectId || !bucketId) {
    throw new Error('Project ID and Bucket ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.storage.getBucket({ bucketId })
  return response
}

/**
 * Query function to fetch files in a bucket
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchBucketFiles(
  projectId: string,
  bucketId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<Models.FileList> {
  if (!projectId || !bucketId) {
    return { files: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.storage.listFiles({
    bucketId,
    queries,
    search: search?.trim() || undefined,
  })

  return response
}

/**
 * Query function to fetch a single file by ID
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchFile(
  projectId: string,
  bucketId: string,
  fileId: string,
): Promise<Models.File> {
  if (!projectId || !bucketId || !fileId) {
    throw new Error('Project ID, Bucket ID, and File ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.storage.getFile({ bucketId, fileId })
  return response
}

/**
 * Query function to fetch file tokens
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchFileTokens(
  projectId: string,
  bucketId: string,
  fileId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<Models.ResourceTokenList> {
  if (!projectId || !bucketId || !fileId) {
    return { tokens: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.tokens.list({
    bucketId,
    fileId,
    queries,
  })
  return response
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching paginated buckets for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function bucketsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: ['buckets', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectBuckets(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch paginated buckets for a project
 *
 * This is useful for displaying project buckets with pagination and search.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated buckets with loading state
 */
export function useProjectBuckets(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: bucketsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(bucketsQueryOptions(projectId, page, limit, search))

  const buckets = useMemo(() => {
    if (!bucketsData?.buckets) return []
    return bucketsData.buckets
  }, [bucketsData])

  const totalPages = useMemo(() => {
    if (!bucketsData?.total) return 0
    return Math.ceil(bucketsData.total / limit)
  }, [bucketsData?.total, limit])

  return {
    buckets,
    total: bucketsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single bucket by ID
 */
export function useBucket(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['bucket', 'project', projectId, bucketId],
    queryFn: () => fetchBucket(projectId!, bucketId!),
    enabled: !!projectId && !!bucketId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch files in a bucket
 */
export function useBucketFiles(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return useQuery({
    queryKey: [
      'files',
      'project',
      projectId,
      'bucket',
      bucketId,
      page,
      limit,
      search,
    ],
    queryFn: () => fetchBucketFiles(projectId!, bucketId!, page, limit, search),
    enabled: !!projectId && !!bucketId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch a single file by ID
 */
export function useFile(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  fileId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
    queryFn: () => fetchFile(projectId!, bucketId!, fileId!),
    enabled: !!projectId && !!bucketId && !!fileId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch file tokens with pagination
 */
export function useFileTokens(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  fileId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return useQuery({
    queryKey: [
      'file-tokens',
      'project',
      projectId,
      'bucket',
      bucketId,
      fileId,
      page,
      limit,
    ],
    queryFn: () => fetchFileTokens(projectId!, bucketId!, fileId!, page, limit),
    enabled: !!projectId && !!bucketId && !!fileId,
    staleTime: DEFAULT_STALE_TIME,
  })
}
