import type {
  UsageBreakdownItem,
  UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import {
  isUsageProjectResourceType,
  parseTableUsageResourceType,
} from '@/lib/usage/usage-resource-filters'

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
    if (isUsageProjectResourceType(item.resourceType)) continue

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
  resourceTypeEntry?: T
} {
  return {
    standardEntries: entries.filter(
      (entry) =>
        entry.section.dimension !== 'resource' &&
        entry.section.dimension !== 'resourceType',
    ),
    resourceEntry: entries.find(
      (entry) => entry.section.dimension === 'resource',
    ),
    resourceTypeEntry: entries.find(
      (entry) => entry.section.dimension === 'resourceType',
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
