import { sdk } from '@/lib/appwrite/sdk'
import {
  deleteProjectDatabase,
  invalidateDatabaseModelAndType,
} from '@/lib/react-query/hooks/databases'
import type {
  DowngradeResourceType,
  ProjectDowngradeResources,
} from '@/lib/billing/downgrade-plan-limits'
import type { DatabaseRouteKind } from '@/lib/database-routes'

export type DowngradeDatabaseRef = { $id: string; dbKind: DatabaseRouteKind }

export type ResourcesToDeleteEntry = {
  databases?: DowngradeDatabaseRef[]
  buckets?: string[]
  functions?: string[]
  sites?: string[]
}

export type ResourcesToDelete = Record<string, ResourcesToDeleteEntry>

export async function deleteDowngradeResources(
  resourcesToDelete: ResourcesToDelete,
): Promise<void> {
  const tasks: Promise<unknown>[] = []

  for (const [projectId, resourceMap] of Object.entries(resourcesToDelete)) {
    if (!resourceMap) continue

    const projectSdk = sdk.forProject(projectId)

    for (const database of resourceMap.databases ?? []) {
      // Route through the owning product API (tables/documents/vectors), not
      // always tablesDB. Native dedicated DBs are handled separately.
      tasks.push(
        deleteProjectDatabase(projectId, database.$id, database.dbKind).then(
          () => {
            invalidateDatabaseModelAndType(projectId, database.$id)
          },
        ),
      )
    }

    for (const bucketId of resourceMap.buckets ?? []) {
      tasks.push(projectSdk.storage.deleteBucket({ bucketId }))
    }

    for (const functionId of resourceMap.functions ?? []) {
      tasks.push(projectSdk.functions.delete({ functionId }))
    }

    for (const siteId of resourceMap.sites ?? []) {
      tasks.push(projectSdk.sites.delete({ siteId }))
    }
  }

  await Promise.all(tasks)
}

export function buildResourcesToDelete(
  projectId: string,
  resources: ProjectDowngradeResources,
  keepSelections: Partial<Record<DowngradeResourceType, Set<string>>>,
): ResourcesToDeleteEntry {
  const result: ResourcesToDeleteEntry = {}

  for (const type of Object.keys(resources) as DowngradeResourceType[]) {
    const keepIds = keepSelections[type] ?? new Set<string>()
    const remainingItems = resources[type].items.filter(
      (item) => !keepIds.has(item.$id),
    )

    if (remainingItems.length === 0) continue

    if (type === 'databases') {
      result.databases = remainingItems.map((item) => ({
        $id: item.$id,
        dbKind: item.dbKind ?? 'tablesdb',
      }))
    } else {
      result[type] = remainingItems.map((item) => item.$id)
    }
  }

  return result
}
