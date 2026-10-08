import { redirect } from '@tanstack/react-router'
import { bucketsQueryOptions } from '@/lib/react-query/hooks'

/** Placeholder bucket id (same pattern as database `tables/-/rows`). */
export const STORAGE_PLACEHOLDER_BUCKET_ID = '-' as const

export function isStoragePlaceholderBucketId(
  bucketId: string | null | undefined,
): boolean {
  return bucketId === STORAGE_PLACEHOLDER_BUCKET_ID
}

/** First-page bucket list for sidebar + default-bucket redirect (name A→Z). */
export const STORAGE_SIDEBAR_BUCKETS_PREFETCH = 100

export function storageSidebarBucketsQueryOptions(projectId: string) {
  return bucketsQueryOptions(
    projectId,
    0,
    STORAGE_SIDEBAR_BUCKETS_PREFETCH,
    undefined,
    undefined,
    'name',
    'asc',
  )
}

/** TanStack Router: skip redirects during intent preload (`defaultPreload: 'intent'`). */
export function isRealStorageNavigation(
  cause: 'preload' | 'enter' | 'stay',
  preload: boolean,
) {
  return cause !== 'preload' && !preload
}

export function storageHomeNavigation(projectId: string) {
  return {
    to: '/projects/$projectId/storage/$bucketId' as const,
    params: {
      projectId,
      bucketId: STORAGE_PLACEHOLDER_BUCKET_ID,
    },
  }
}

export function redirectStorageFirstBucketOrPlaceholder(
  projectId: string,
  bucketsData: { buckets?: { $id?: string }[] },
): never {
  const firstId = bucketsData.buckets?.[0]?.$id
  throw redirect({
    to: '/projects/$projectId/storage/$bucketId',
    params: {
      projectId,
      bucketId: firstId ?? STORAGE_PLACEHOLDER_BUCKET_ID,
    },
    replace: true,
  })
}
