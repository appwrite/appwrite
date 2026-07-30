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

export type PartitionedUsageBreakdownResourceIds = {
  bucketIds: string[]
  computeIds: string[]
  databaseIds: string[]
  tableIds: string[]
  /** Missing or unrecognized resourceType - try every lookup. */
  unknownIds: string[]
}

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

/**
 * Split breakdown rows by resourceType before name lookups.
 *
 * Each lookup normalizes with its own small limit (6–8). Passing a flat mix of
 * bucket + database + function + site IDs (e.g. storage usage) truncates away
 * later families, so functions/sites never resolve. Overview already partitions;
 * usage pages must do the same.
 */
export function partitionUsageBreakdownResourceIds(
  items: UsageBreakdownItem[],
): PartitionedUsageBreakdownResourceIds {
  const bucketIds = new Set<string>()
  const computeIds = new Set<string>()
  const databaseIds = new Set<string>()
  const tableIds = new Set<string>()
  const unknownIds = new Set<string>()

  for (const item of items) {
    if (isUsageProjectResourceType(item.resourceType)) continue

    const resourceId = (item.resourceId ?? item.label).trim()
    if (!resourceId) continue

    const resourceType = item.resourceType?.trim() ?? ''

    if (resourceType === 'bucket') {
      bucketIds.add(resourceId)
      continue
    }

    if (resourceType === 'function' || resourceType === 'site') {
      computeIds.add(resourceId)
      continue
    }

    if (resourceType === 'database' || resourceType === 'dedicatedDatabases') {
      databaseIds.add(resourceId)
      continue
    }

    const tableDatabaseId = parseTableUsageResourceType(resourceType)
    if (tableDatabaseId) {
      tableIds.add(resourceId)
      tableIds.add(`${tableDatabaseId}/${resourceId}`)
      continue
    }

    unknownIds.add(resourceId)
  }

  return {
    bucketIds: Array.from(bucketIds),
    computeIds: Array.from(computeIds),
    databaseIds: Array.from(databaseIds),
    tableIds: Array.from(tableIds),
    unknownIds: Array.from(unknownIds),
  }
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
