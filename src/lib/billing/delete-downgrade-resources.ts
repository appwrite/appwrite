import { sdk } from '@/lib/appwrite/sdk'
import {
  deleteProjectDatabase,
  invalidateDatabaseModelAndType,
} from '@/lib/react-query/hooks/databases'
import type {
  DowngradeResourceType,
  ProjectDowngradeResources,
} from '@/lib/billing/downgrade-plan-limits'

export type ResourcesToDelete = Record<
  string,
  Partial<Record<DowngradeResourceType, string[]>>
>

export async function deleteDowngradeResources(
  resourcesToDelete: ResourcesToDelete,
): Promise<void> {
  const tasks: Promise<unknown>[] = []

  for (const [projectId, resourceMap] of Object.entries(resourcesToDelete)) {
    if (!resourceMap) continue

    const projectSdk = sdk.forProject(projectId)

    for (const databaseId of resourceMap.databases ?? []) {
      // Route through the owning product API (tables/documents/vectors), not
      // always tablesDB. Native dedicated DBs are handled separately.
      tasks.push(
        deleteProjectDatabase(projectId, databaseId).then(() => {
          invalidateDatabaseModelAndType(projectId, databaseId)
        }),
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
): Partial<Record<DowngradeResourceType, string[]>> {
  const result: Partial<Record<DowngradeResourceType, string[]>> = {}

  for (const type of Object.keys(resources) as DowngradeResourceType[]) {
    const keepIds = keepSelections[type] ?? new Set<string>()
    const deleteIds = resources[type].items
      .map((item) => item.$id)
      .filter((id) => !keepIds.has(id))

    if (deleteIds.length > 0) {
      result[type] = deleteIds
    }
  }

  return Object.keys(result).length > 0 ? result : {}
}
