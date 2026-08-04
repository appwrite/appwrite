import { Query } from '@appwrite.io/console'

/** Resource type for dedicated database instance host metrics. */
export const DEDICATED_DATABASE_USAGE_RESOURCE_TYPE =
  'dedicatedDatabases' as const

/**
 * Build Utopia `queries[]` for usage.listEvents / usage.listGauges.
 * The SDK does not accept top-level `resourceId` / `resourceType` - filters must
 * go through `queries` (`equal("resourceId", …)`, `equal("resourceType", …)`).
 * For dedicated databases, `ordinal` scopes gauges to a cluster node
 * (0 = primary, 1+ = replicas).
 */
export function buildUsageResourceFilterQueries(options: {
  resourceId?: string | null
  resourceType?: string | null
  /** Cluster node index: 0 = primary, 1+ = replicas. */
  ordinal?: number | string | null
  queries?: string[] | null
}): string[] | undefined {
  const merged: string[] = [...(options.queries ?? [])]

  const resourceType = options.resourceType?.trim()
  if (resourceType) {
    merged.push(Query.equal('resourceType', resourceType))
  }

  const resourceId = options.resourceId?.trim()
  if (resourceId) {
    merged.push(Query.equal('resourceId', resourceId))
  }

  if (
    options.ordinal !== undefined &&
    options.ordinal !== null &&
    options.ordinal !== ''
  ) {
    merged.push(Query.equal('ordinal', String(options.ordinal)))
  }

  return merged.length > 0 ? merged : undefined
}
