import { fetchProjectDatabases } from '@/lib/react-query/hooks/databases'
import { fetchProjectBuckets } from '@/lib/react-query/hooks/storage'
import { fetchProjectFunctions } from '@/lib/react-query/hooks/functions'
import { fetchProjectSites } from '@/lib/react-query/hooks/sites'
import { fetchProjectTeams } from '@/lib/react-query/hooks/users'
import { fetchProjectTopics } from '@/lib/react-query/hooks/messaging'
import { fetchPlatforms } from '@/lib/react-query/hooks/projects'
import { fetchProjectWebhooks } from '@/lib/react-query/hooks/webhooks'
import { fetchFirewallRules } from '@/lib/react-query/hooks/waf'
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

function mapDatabaseItems<
  T extends { $id: string; name?: string; type?: string },
>(items: T[] | undefined, total: number | undefined) {
  return {
    items: (items ?? []).map((item) => ({
      $id: item.$id,
      name: item.name?.trim() || 'Untitled',
      dbKind: databaseRouteKindFromApiType(item.type),
    })),
    total: total ?? items?.length ?? 0,
  }
}

/**
 * A resource type the plan does not cap is still fetched, because the caller
 * decides that from the limits rather than from here. A single failing list
 * call must not blank the whole step, so each one falls back to empty and the
 * server-side compliance check remains the gate on whether the plan can change.
 */
async function safeGroup<T>(
  load: () => Promise<T>,
  map: (value: T) => { items: { $id: string; name: string }[]; total: number },
) {
  try {
    return map(await load())
  } catch {
    return { items: [], total: 0 }
  }
}

export async function fetchProjectDowngradeResources(
  projectId: string,
): Promise<ProjectDowngradeResources> {
  const [
    databases,
    buckets,
    functions,
    sites,
    teams,
    topics,
    platforms,
    webhooks,
    wafRules,
  ] = await Promise.all([
    fetchProjectDatabases(projectId, 0, DOWNGRADE_LIST_LIMIT),
    fetchProjectBuckets(projectId, 0, DOWNGRADE_LIST_LIMIT),
    fetchProjectFunctions(projectId, 0, DOWNGRADE_LIST_LIMIT),
    fetchProjectSites(projectId, 0, DOWNGRADE_LIST_LIMIT),
    safeGroup(
      () => fetchProjectTeams(projectId, 0, DOWNGRADE_LIST_LIMIT),
      (value) => mapItems(value.teams, value.total),
    ),
    safeGroup(
      () => fetchProjectTopics(projectId, 0, DOWNGRADE_LIST_LIMIT),
      (value) => mapItems(value.topics, value.total),
    ),
    safeGroup(
      () => fetchPlatforms(projectId),
      (value) => mapItems(value.platforms, value.total),
    ),
    safeGroup(
      () => fetchProjectWebhooks(projectId),
      (value) => mapItems(value.webhooks, value.total),
    ),
    safeGroup(
      () => fetchFirewallRules(projectId, 0, DOWNGRADE_LIST_LIMIT),
      (value) => mapItems(value.rules, value.total),
    ),
  ])

  return {
    databases: mapDatabaseItems(databases.databases, databases.total),
    buckets: mapItems(buckets.buckets, buckets.total),
    functions: mapItems(functions.functions, functions.total),
    sites: mapItems(sites.sites, sites.total),
    teams,
    topics,
    platforms,
    webhooks,
    wafRules,
  }
}
