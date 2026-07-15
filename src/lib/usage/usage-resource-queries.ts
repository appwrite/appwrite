import { Query } from '@appwrite.io/console'

/** Resource type for dedicated database instance host metrics. */
export const DEDICATED_DATABASE_USAGE_RESOURCE_TYPE =
  'dedicatedDatabases' as const

/**
 * Build Utopia `queries[]` for usage.listEvents / usage.listGauges.
 * The SDK does not accept top-level `resourceId` / `resourceType` — filters must
 * go through `queries` (`equal("resourceId", …)`, `equal("resourceType", …)`).
 */
export function buildUsageResourceFilterQueries(options: {
  resourceId?: string | null
  resourceType?: string | null
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

  return merged.length > 0 ? merged : undefined
}
