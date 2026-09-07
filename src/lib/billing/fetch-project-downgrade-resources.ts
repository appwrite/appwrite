import { fetchProjectDatabases } from '@/lib/react-query/hooks/databases'
import { fetchProjectBuckets } from '@/lib/react-query/hooks/storage'
import { fetchProjectFunctions } from '@/lib/react-query/hooks/functions'
import { fetchProjectSites } from '@/lib/react-query/hooks/sites'
import { fetchProjectTeams } from '@/lib/react-query/hooks/users'
import { fetchProjectTopics } from '@/lib/react-query/hooks/messaging'
import { fetchPlatforms } from '@/lib/react-query/hooks/projects'
import { fetchProjectWebhooks } from '@/lib/react-query/hooks/webhooks'
import { fetchFirewallRules } from '@/lib/react-query/hooks/waf'
import type {
  DowngradeResourceGroup,
  ProjectDowngradeResources,
} from '@/lib/billing/downgrade-plan-limits'
import { databaseRouteKindFromApiType } from '@/lib/database-routes'

const DOWNGRADE_PAGE_SIZE = 1000

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
 * Compliance is checked against `total`, but only fetched items can be shown
 * and selected, so a capped single page leaves an over-limit project unable to
 * ever resolve the step. Exported for unit tests.
 */
export async function fetchAllPages<T>(
  load: (page: number, limit: number) => Promise<{ items: T[]; total: number }>,
): Promise<{ items: T[]; total: number }> {
  const items: T[] = []
  let total = 0

  for (let page = 0; ; page += 1) {
    const result = await load(page, DOWNGRADE_PAGE_SIZE)
    items.push(...result.items)
    total = result.total

    // A `total` the pages never reach (server disagreement, permission
    // filtering) would spin forever without the empty-page break.
    if (result.items.length === 0 || items.length >= total) {
      break
    }
  }

  return { items, total }
}

/**
 * A resource type the plan does not cap is still fetched, because the caller
 * decides that from the limits rather than from here. A single failing list
 * call must not blank the whole step, so each one falls back to empty and the
 * server-side compliance check remains the gate on whether the plan can change.
 */
async function safeGroup<T>(
  load: () => Promise<T>,
  map: (value: T) => DowngradeResourceGroup,
): Promise<DowngradeResourceGroup> {
  try {
    return map(await load())
  } catch {
    return { items: [], total: 0 }
  }
}

function safePagedGroup<T extends { $id: string; name?: string }>(
  load: (page: number, limit: number) => Promise<{ items: T[]; total: number }>,
) {
  return safeGroup(
    () => fetchAllPages(load),
    (value) => mapItems(value.items, value.total),
  )
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
    safeGroup(
      () =>
        fetchAllPages(async (page, limit) => {
          const value = await fetchProjectDatabases(projectId, page, limit)
          return { items: value.databases ?? [], total: value.total ?? 0 }
        }),
      (value) => mapDatabaseItems(value.items, value.total),
    ),
    safePagedGroup(async (page, limit) => {
      const value = await fetchProjectBuckets(projectId, page, limit)
      return { items: value.buckets ?? [], total: value.total ?? 0 }
    }),
    safePagedGroup(async (page, limit) => {
      const value = await fetchProjectFunctions(projectId, page, limit)
      return { items: value.functions ?? [], total: value.total ?? 0 }
    }),
    safePagedGroup(async (page, limit) => {
      const value = await fetchProjectSites(projectId, page, limit)
      return { items: value.sites ?? [], total: value.total ?? 0 }
    }),
    safePagedGroup(async (page, limit) => {
      const value = await fetchProjectTeams(projectId, page, limit)
      return { items: value.teams ?? [], total: value.total ?? 0 }
    }),
    safePagedGroup(async (page, limit) => {
      const value = await fetchProjectTopics(projectId, page, limit)
      return { items: value.topics ?? [], total: value.total ?? 0 }
    }),
    safeGroup(
      () => fetchPlatforms(projectId),
      (value) => mapItems(value.platforms, value.total),
    ),
    safeGroup(
      () => fetchProjectWebhooks(projectId),
      (value) => mapItems(value.webhooks, value.total),
    ),
    safePagedGroup(async (page, limit) => {
      const value = await fetchFirewallRules(projectId, page, limit)
      return { items: value.rules ?? [], total: value.total ?? 0 }
    }),
  ])

  return {
    databases,
    buckets,
    functions,
    sites,
    teams,
    topics,
    platforms,
    webhooks,
    wafRules,
  }
}
