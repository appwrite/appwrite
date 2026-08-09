import type { DatabaseBreakdownResource } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResource } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResource } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResource } from '@/lib/usage/resolve-table-breakdown-resources'
import { getDatabaseBreakdownServiceLabel } from '@/lib/usage/resolve-database-breakdown-resources'
import { getComputeBreakdownResourceTypeLabel } from '@/lib/usage/resolve-compute-breakdown-resources'
import { getStorageBreakdownResourceTypeLabel } from '@/lib/usage/resolve-storage-breakdown-resources'
import { getTableBreakdownResourceTypeLabel } from '@/lib/usage/resolve-table-breakdown-resources'
import { resolveDatabaseBreakdownResource } from '@/lib/usage/resolve-database-breakdown-resources'
import { resolveComputeBreakdownResource } from '@/lib/usage/resolve-compute-breakdown-resources'
import { resolveStorageBreakdownResource } from '@/lib/usage/resolve-storage-breakdown-resources'
import { resolveTableBreakdownResource } from '@/lib/usage/resolve-table-breakdown-resources'
import type { UsageBreakdownItem } from '@/lib/usage/usage-events-common'
import type { DatabaseBreakdownResourceMap } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResourceMap } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResourceMap } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResourceMap } from '@/lib/usage/resolve-table-breakdown-resources'

export type UsageBreakdownFilterEntry = {
  dimension: string
  value: string
}

export type ResolvedUsageResourceBreakdown = {
  typeLabel: string
  name: string
  computeResource?: ComputeBreakdownResource
  storageResource?: StorageBreakdownResource
  tableResource?: TableBreakdownResource
  databaseResource?: DatabaseBreakdownResource
}

export function parseTableUsageResourceType(
  resourceType: string,
): string | undefined {
  const match = /^database\/([^/]+)\/table$/.exec(resourceType.trim())
  return match?.[1]
}

export function formatUsageResourceTypeLabel(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return 'Unknown'

  if (trimmed === 'bucket') return 'Bucket'
  if (trimmed === 'function') return 'Function'
  if (trimmed === 'site') return 'Site'
  if (trimmed === 'database' || trimmed === 'dedicatedDatabases') {
    return 'Database'
  }
  if (trimmed === 'project') return 'Project'

  const tableMatch = /^database\/([^/]+)\/table$/.exec(trimmed)
  if (tableMatch) {
    return `Table (${tableMatch[1]})`
  }

  return trimmed
}

/** Project-scoped usage has a resource type but no meaningful resource ID. */
export function isUsageProjectResourceType(
  resourceType: string | null | undefined,
): boolean {
  return resourceType?.trim().toLowerCase() === 'project'
}

type UsageResourceBreakdownLookups = {
  computeLookup?: ComputeBreakdownResourceMap | null
  storageLookup?: StorageBreakdownResourceMap | null
  tableLookup?: TableBreakdownResourceMap | null
  databaseLookup?: DatabaseBreakdownResourceMap | null
}

export function resolveUsageResourceBreakdownItem(
  item: UsageBreakdownItem,
  lookups: UsageResourceBreakdownLookups,
): ResolvedUsageResourceBreakdown {
  const resourceId = (item.resourceId ?? item.label).trim()
  const resourceType = item.resourceType?.trim() ?? ''

  if (isUsageProjectResourceType(resourceType)) {
    return {
      typeLabel: formatUsageResourceTypeLabel('project'),
      name: '',
    }
  }

  if (resourceType === 'function' || resourceType === 'site') {
    const computeResource = resolveComputeBreakdownResource(
      resourceId,
      lookups.computeLookup,
    )
    if (computeResource) {
      return {
        typeLabel: getComputeBreakdownResourceTypeLabel(computeResource.type),
        name: computeResource.name,
        computeResource,
      }
    }
  }

  if (resourceType === 'bucket') {
    const storageResource = resolveStorageBreakdownResource(
      resourceId,
      lookups.storageLookup,
    )
    if (storageResource) {
      return {
        typeLabel: getStorageBreakdownResourceTypeLabel(),
        name: storageResource.name,
        storageResource,
      }
    }
  }

  if (resourceType === 'database' || resourceType === 'dedicatedDatabases') {
    const databaseResource = resolveDatabaseBreakdownResource(
      resourceId,
      lookups.databaseLookup,
    )
    if (databaseResource) {
      return {
        typeLabel: getDatabaseBreakdownServiceLabel(databaseResource.databaseType),
        name: databaseResource.name,
        databaseResource,
      }
    }
  }

  const tableDatabaseId = parseTableUsageResourceType(resourceType)
  if (tableDatabaseId) {
    const tableLabel = `${tableDatabaseId}/${resourceId}`
    const tableResource =
      resolveTableBreakdownResource(tableLabel, lookups.tableLookup) ??
      resolveTableBreakdownResource(resourceId, lookups.tableLookup)
    if (tableResource) {
      return {
        typeLabel: getTableBreakdownResourceTypeLabel(tableResource.databaseType),
        name: tableResource.name,
        tableResource,
      }
    }
  }

  if (!resourceType) {
    const computeResource = resolveComputeBreakdownResource(
      resourceId,
      lookups.computeLookup,
    )
    if (computeResource) {
      return {
        typeLabel: getComputeBreakdownResourceTypeLabel(computeResource.type),
        name: computeResource.name,
        computeResource,
      }
    }

    const storageResource = resolveStorageBreakdownResource(
      resourceId,
      lookups.storageLookup,
    )
    if (storageResource) {
      return {
        typeLabel: getStorageBreakdownResourceTypeLabel(),
        name: storageResource.name,
        storageResource,
      }
    }

    const tableResource = resolveTableBreakdownResource(
      resourceId,
      lookups.tableLookup,
    )
    if (tableResource) {
      return {
        typeLabel: getTableBreakdownResourceTypeLabel(tableResource.databaseType),
        name: tableResource.name,
        tableResource,
      }
    }

    const databaseResource = resolveDatabaseBreakdownResource(
      resourceId,
      lookups.databaseLookup,
    )
    if (databaseResource) {
      return {
        typeLabel: getDatabaseBreakdownServiceLabel(databaseResource.databaseType),
        name: databaseResource.name,
        databaseResource,
      }
    }
  }

  return {
    typeLabel: formatUsageResourceTypeLabel(resourceType || 'Unknown'),
    name: resourceId,
  }
}

export function getUsageResourceFilterEntries(
  resourceIdLabel: string,
  resources: {
    computeResource?: ComputeBreakdownResource
    storageResource?: StorageBreakdownResource
    tableResource?: TableBreakdownResource
    databaseResource?: DatabaseBreakdownResource
  },
  resourceType?: string,
): UsageBreakdownFilterEntry[] {
  const normalizedType = resourceType?.trim() ?? ''
  if (isUsageProjectResourceType(normalizedType)) {
    return [{ dimension: 'resourceType', value: 'project' }]
  }

  const resourceId = resourceIdLabel.trim()
  if (!resourceId) return []

  if (normalizedType) {
    return [
      { dimension: 'resourceId', value: resourceId },
      { dimension: 'resourceType', value: normalizedType },
    ]
  }

  if (resources.computeResource) {
    return [
      { dimension: 'resourceId', value: resourceId },
      { dimension: 'resourceType', value: resources.computeResource.type },
    ]
  }

  if (resources.storageResource) {
    return [
      { dimension: 'resourceId', value: resourceId },
      { dimension: 'resourceType', value: 'bucket' },
    ]
  }

  if (resources.tableResource) {
    return [
      { dimension: 'resourceId', value: resourceId },
      {
        dimension: 'resourceType',
        value: `database/${resources.tableResource.databaseId}/table`,
      },
    ]
  }

  if (resources.databaseResource) {
    return [
      { dimension: 'resourceId', value: resourceId },
      { dimension: 'resourceType', value: 'database' },
    ]
  }

  return [{ dimension: 'resourceId', value: resourceId }]
}
