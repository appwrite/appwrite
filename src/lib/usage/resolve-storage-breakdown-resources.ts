import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'

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
  return [
    ...new Set(resourceIds.filter((id) => typeof id === 'string' && id.trim())),
  ].slice(0, OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT)
}

export async function fetchProjectBucketsByIds(
  projectId: string,
  bucketIds: string[],
): Promise<{ buckets: StorageBreakdownResource[] }> {
  const ids = normalizeStorageBreakdownResourceIds(bucketIds)
  if (!projectId || ids.length === 0) {
    return { buckets: [] }
  }

  const idQuery =
    ids.length === 1
      ? Query.equal('$id', ids[0])
      : Query.or(ids.map((id) => Query.equal('$id', id)))

  const response = await sdk.forProject(projectId).storage.listBuckets({
    queries: [idQuery, Query.limit(ids.length)],
  })

  return {
    buckets: (response.buckets ?? []).map((bucket) => ({
      id: bucket.$id,
      name: bucket.name,
    })),
  }
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
