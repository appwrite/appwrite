import {
  buildIdLookupQueryBatches,
  fetchLookupBatches,
  normalizeIds,
} from '@/lib/appwrite-id'
import { sdk } from '@/lib/appwrite/sdk'

export interface StorageBreakdownResource {
  id: string
  name: string
}

export type StorageBreakdownResourceMap = Record<
  string,
  StorageBreakdownResource
>

export function normalizeStorageBreakdownResourceIds(
  resourceIds: string[],
): string[] {
  return normalizeIds(resourceIds)
}

export async function fetchProjectBucketsByIds(
  projectId: string,
  bucketIds: string[],
): Promise<{ buckets: StorageBreakdownResource[] }> {
  const batches = buildIdLookupQueryBatches(bucketIds)
  if (!projectId || batches.length === 0) {
    return { buckets: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  const buckets = await fetchLookupBatches(batches, async (queries) =>
    ((await projectSdk.storage.listBuckets({ queries })).buckets ?? []).map(
      (bucket) => ({ id: bucket.$id, name: bucket.name }),
    ),
  )

  return { buckets }
}

export async function fetchStorageBreakdownResources(
  projectId: string,
  resourceIds: string[],
): Promise<{ resources: StorageBreakdownResourceMap }> {
  const ids = normalizeStorageBreakdownResourceIds(resourceIds)
  if (!projectId || ids.length === 0) {
    return { resources: {} }
  }

  const { buckets } = await fetchProjectBucketsByIds(projectId, ids).catch(
    () => ({ buckets: [] }),
  )

  const resources: StorageBreakdownResourceMap = {}
  for (const bucket of buckets) {
    resources[bucket.id] = bucket
  }

  return { resources }
}

export function resolveStorageBreakdownResource(
  resourceId: string,
  lookup: StorageBreakdownResourceMap | null | undefined,
): StorageBreakdownResource | undefined {
  return lookup?.[resourceId]
}

export function getStorageBreakdownResourceRoute(
  projectId: string,
  resource: StorageBreakdownResource,
): {
  to: '/projects/$projectId/storage/$bucketId'
  params: { projectId: string; bucketId: string }
} {
  return {
    to: '/projects/$projectId/storage/$bucketId',
    params: { projectId, bucketId: resource.id },
  }
}

export function getStorageBreakdownResourceTypeLabel(): string {
  return 'Storage'
}
