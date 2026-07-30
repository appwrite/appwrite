import { fetchProjectDatabases } from '@/lib/react-query/hooks/databases'
import { fetchProjectBuckets } from '@/lib/react-query/hooks/storage'
import { fetchProjectFunctions } from '@/lib/react-query/hooks/functions'
import { fetchProjectSites } from '@/lib/react-query/hooks/sites'
import type { ProjectDowngradeResources } from '@/lib/billing/downgrade-plan-limits'
import { databaseRouteKindFromApiType } from '@/lib/database-routes'

const DOWNGRADE_LIST_LIMIT = 1000

function mapItems<T extends { $id: string; name?: string }>(
  items: T[] | undefined,
  total: number | undefined,
) {
  return {
    items: (items ?? []).map((item) => ({
      $id: item.$id,
      name: item.name?.trim() || 'Untitled',
    })),
    total: total ?? items?.length ?? 0,
  }
}

function mapDatabaseItems<T extends { $id: string; name?: string; type?: string }>(
  items: T[] | undefined,
  total: number | undefined,
) {
  return {
    items: (items ?? []).map((item) => ({
      $id: item.$id,
      name: item.name?.trim() || 'Untitled',
      dbKind: databaseRouteKindFromApiType(item.type),
    })),
    total: total ?? items?.length ?? 0,
  }
}

export async function fetchProjectDowngradeResources(
  projectId: string,
): Promise<ProjectDowngradeResources> {
  const [databases, buckets, functions, sites] = await Promise.all([
    fetchProjectDatabases(projectId, 0, DOWNGRADE_LIST_LIMIT),
    fetchProjectBuckets(projectId, 0, DOWNGRADE_LIST_LIMIT),
    fetchProjectFunctions(projectId, 0, DOWNGRADE_LIST_LIMIT),
    fetchProjectSites(projectId, 0, DOWNGRADE_LIST_LIMIT),
  ])

  return {
    databases: mapDatabaseItems(databases.databases, databases.total),
    buckets: mapItems(buckets.buckets, buckets.total),
    functions: mapItems(functions.functions, functions.total),
    sites: mapItems(sites.sites, sites.total),
  }
}
