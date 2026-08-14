/**
 * React Query hooks for Storage
 *
 * Handles buckets, files, and file tokens.
 */

import { useQuery, queryOptions, keepPreviousData } from '@tanstack/react-query'
import type { QueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { buildAttributePrefixSearchQueries } from '@/lib/appwrite-id'
import { sdk } from '@/lib/appwrite/sdk'
import { isStoragePlaceholderBucketId } from '@/lib/storage-routes'
import {
  DEFAULT_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  FILE_TOKENS_DEFAULT_PAGE_SIZE,
} from './constants'

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
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @returns Paginated buckets with total count
 */
export const BUCKETS_DEFAULT_SORT_BY = '$createdAt'
export const BUCKETS_DEFAULT_SORT_ORDER = 'desc' as const

export async function fetchProjectBuckets(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = BUCKETS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = BUCKETS_DEFAULT_SORT_ORDER,
): Promise<Models.BucketList> {
  if (!projectId) {
    return { buckets: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const queries = [
    ...(filterQueries ?? []),
    ...buildAttributePrefixSearchQueries(['name', '$id'], search),
    orderQuery,
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.storage.listBuckets({ queries })

  return {
    buckets: response.buckets || [],
    total: response.total || 0,
  }
}

/**
 * From cached bucket lists (any query variant), pick a neighbor of the deleted
 * bucket: same list order, prefer following bucket, else previous. Returns null
 * if the deleted bucket was the only one or not found in any list.
 */
export function pickNextBucketIdAfterDelete(
  bucketLists: Models.Bucket[][],
  deletedBucketId: string,
): string | null {
  for (const buckets of bucketLists) {
    if (buckets.length === 0) continue
    const idx = buckets.findIndex((b) => b.$id === deletedBucketId)
    if (idx === -1) continue
    if (buckets.length === 1) return null
    if (idx < buckets.length - 1) return buckets[idx + 1]!.$id
    return buckets[idx - 1]!.$id
  }
  return null
}

/**
 * Read all cached bucket arrays for a project (any pagination / sort key).
 */
export function getCachedBucketListsFromQueryClient(
  queryClient: QueryClient,
  projectId: string | undefined,
): Models.Bucket[][] {
  if (!projectId) return []
  return queryClient
    .getQueriesData<Models.BucketList>({ queryKey: ['buckets', 'project', projectId] })
    .map(([, data]) => data?.buckets ?? [])
}

/**
 * Resolve a bucket for UI (header, etc.) from the single-bucket query or any
 * cached buckets list for the project - avoids a brief empty state while the
 * detail query refetches during the same navigation (e.g. file selection).
 */
export function getBucketFromProjectCaches(
  queryClient: QueryClient,
  projectId: string | undefined,
  bucketId: string | undefined,
): Models.Bucket | undefined {
  if (!projectId || !bucketId) return undefined
  const detail = queryClient.getQueryData<Models.Bucket>([
    'bucket',
    'project',
    projectId,
    bucketId,
  ])
  if (detail) return detail
  for (const list of getCachedBucketListsFromQueryClient(
    queryClient,
    projectId,
  )) {
    const b = list.find((x) => x.$id === bucketId)
    if (b) return b
  }
  return undefined
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
  if (!projectId || !bucketId || isStoragePlaceholderBucketId(bucketId)) {
    throw new Error('Project ID and Bucket ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.storage.getBucket({ bucketId })
  return response
}

/**
 * Query filter for CSV-compatible files (by mime type or .csv extension).
 * Use with listFiles to only return files suitable for CSV import.
 */
const CSV_FILE_QUERIES = [
  Query.or([
    Query.equal('mimeType', 'text/csv'),
    Query.equal('mimeType', 'text/plain'),
    Query.startsWith('mimeType', 'text/'),
    Query.endsWith('name', '.csv'),
  ]),
]

export const FILES_DEFAULT_SORT_BY = '$createdAt'
export const FILES_DEFAULT_SORT_ORDER = 'desc' as const

/**
 * Query function to fetch files in a bucket
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 * @param csvOnly - When true, only return CSV-compatible files (mimeType text/csv, text/plain, text/*, or name ending in .csv)
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 */
export async function fetchBucketFiles(
  projectId: string,
  bucketId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  csvOnly?: boolean,
  filterQueries?: string[],
  sortBy: string = FILES_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = FILES_DEFAULT_SORT_ORDER,
): Promise<Models.FileList> {
  if (!projectId || !bucketId || isStoragePlaceholderBucketId(bucketId)) {
    return { files: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const queries = [
    ...(filterQueries ?? []),
    ...(csvOnly ? CSV_FILE_QUERIES : []),
    orderQuery,
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
 * Query options for fetching files in a bucket
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function bucketFilesQueryOptions(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  csvOnly?: boolean,
  filterQueries?: string[],
  sortBy: string = FILES_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = FILES_DEFAULT_SORT_ORDER,
) {
  return queryOptions({
    queryKey: [
      'files',
      'project',
      projectId,
      'bucket',
      bucketId,
      page,
      limit,
      search,
      csvOnly,
      filterQueries,
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchBucketFiles(
        projectId!,
        bucketId!,
        page,
        limit,
        search,
        csvOnly,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    enabled:
      !!projectId && !!bucketId && !isStoragePlaceholderBucketId(bucketId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page/search change)
    gcTime: projectId && bucketId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for a single file (storage inspector / detail).
 * Use in route loaders and `useFile` so navigation prefetches match the hook cache.
 */
export function fileQueryOptions(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  fileId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
    queryFn: () => fetchFile(projectId!, bucketId!, fileId!),
    enabled:
      !!projectId &&
      !!bucketId &&
      !!fileId &&
      !isStoragePlaceholderBucketId(bucketId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId && bucketId && fileId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for paginated file tokens.
 * Default limit must match `FILE_TOKENS_DEFAULT_PAGE_SIZE` and FileSecurity UI.
 */
export function fileTokensQueryOptions(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  fileId: string | null | undefined,
  page: number = 0,
  limit: number = FILE_TOKENS_DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
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
    queryFn: () =>
      fetchFileTokens(projectId!, bucketId!, fileId!, page, limit),
    enabled:
      !!projectId &&
      !!bucketId &&
      !!fileId &&
      !isStoragePlaceholderBucketId(bucketId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && bucketId && fileId ? 5 * 60 * 1000 : 0,
  })
}

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
  filterQueries?: string[],
  sortBy: string = BUCKETS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = BUCKETS_DEFAULT_SORT_ORDER,
) {
  return queryOptions({
    queryKey: [
      'buckets',
      'project',
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchProjectBuckets(
        projectId!,
        page,
        limit,
        search,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData, // Keep showing previous list until new data is ready (page size/page/search change)
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
  filterQueries?: string[],
  sortBy: string = BUCKETS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = BUCKETS_DEFAULT_SORT_ORDER,
) {
  const {
    data: bucketsData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    bucketsQueryOptions(
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )

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
    isFetched,
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
    enabled:
      !!projectId &&
      !!bucketId &&
      !isStoragePlaceholderBucketId(bucketId),
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch files in a bucket
 * @param csvOnly - When true, only return CSV-compatible files (for CSV import selector)
 */
export function useBucketFiles(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  csvOnly?: boolean,
  filterQueries?: string[],
  sortBy: string = FILES_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = FILES_DEFAULT_SORT_ORDER,
) {
  return useQuery(
    bucketFilesQueryOptions(
      projectId,
      bucketId,
      page,
      limit,
      search,
      csvOnly,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )
}

/** Drop cached single-file data after delete so the inspector cannot show stale previews. */
export function removeCachedFile(
  queryClient: QueryClient,
  projectId: string,
  bucketId: string,
  fileId: string,
) {
  queryClient.removeQueries({
    queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
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
  return useQuery(fileQueryOptions(projectId, bucketId, fileId))
}

/**
 * Hook to fetch file tokens with pagination
 */
export function useFileTokens(
  projectId: string | null | undefined,
  bucketId: string | null | undefined,
  fileId: string | null | undefined,
  page: number = 0,
  limit: number = FILE_TOKENS_DEFAULT_PAGE_SIZE,
) {
  return useQuery(
    fileTokensQueryOptions(projectId, bucketId, fileId, page, limit),
  )
}
