import type { DatabaseBreakdownResource } from '@/lib/usage/resolve-database-breakdown-resources'
import type { ComputeBreakdownResource } from '@/lib/usage/resolve-compute-breakdown-resources'
import type { StorageBreakdownResource } from '@/lib/usage/resolve-storage-breakdown-resources'
import type { TableBreakdownResource } from '@/lib/usage/resolve-table-breakdown-resources'

export type UsageBreakdownFilterEntry = {
  dimension: string
  value: string
}

export function formatUsageResourceTypeLabel(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return 'Unknown'

  if (trimmed === 'bucket') return 'Bucket'
  if (trimmed === 'function') return 'Function'
  if (trimmed === 'site') return 'Site'
  if (trimmed === 'database') return 'Database'
  if (trimmed === 'project') return 'Project'

  const tableMatch = /^database\/([^/]+)\/table$/.exec(trimmed)
  if (tableMatch) {
    return `Table (${tableMatch[1]})`
  }

  return trimmed
}

export function getUsageResourceFilterEntries(
  resourceIdLabel: string,
  resources: {
    computeResource?: ComputeBreakdownResource
    storageResource?: StorageBreakdownResource
    tableResource?: TableBreakdownResource
    databaseResource?: DatabaseBreakdownResource
  },
): UsageBreakdownFilterEntry[] {
  const resourceId = resourceIdLabel.trim()
  if (!resourceId) return []

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
