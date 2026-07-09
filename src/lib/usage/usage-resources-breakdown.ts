import type {
  UsageBreakdownItem,
  UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import { parseTableUsageResourceType } from '@/lib/usage/usage-resource-filters'

export const USAGE_RESOURCES_BREAKDOWN_TITLE = 'Resources'

export type UsageResourceBreakdownDimension = Extract<
  UsageEventBreakdownDimension,
  'resource'
>

export function getUsageBreakdownResourceIds(
  items: UsageBreakdownItem[],
): string[] {
  const ids = new Set<string>()

  for (const item of items) {
    const resourceId = (item.resourceId ?? item.label).trim()
    if (!resourceId) continue

    ids.add(resourceId)

    const tableDatabaseId = parseTableUsageResourceType(item.resourceType ?? '')
    if (tableDatabaseId) {
      ids.add(`${tableDatabaseId}/${resourceId}`)
    }
  }

  return Array.from(ids)
}

export function splitUsageBreakdownEntries<
  T extends { section: { dimension: UsageEventBreakdownDimension } },
>(
  entries: T[],
): {
  standardEntries: T[]
  resourceEntry?: T
} {
  return {
    standardEntries: entries.filter(
      (entry) => entry.section.dimension !== 'resource',
    ),
    resourceEntry: entries.find(
      (entry) => entry.section.dimension === 'resource',
    ),
  }
}

export function collectUsageResourceBreakdownItems<
  T extends {
    section: { dimension: UsageEventBreakdownDimension }
    items: UsageBreakdownItem[]
  },
>(entries: T[]): UsageBreakdownItem[] {
  return entries
    .filter((entry) => entry.section.dimension === 'resource')
    .flatMap((entry) => entry.items)
}
