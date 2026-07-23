/**
 * Display names for billing aggregation resources when the API does not
 * send `name` yet (older cloud builds). Prefer `resource.name` when present.
 */
export const BILLING_ADDON_NAME_FALLBACK: Record<string, string> = {
  addon_baa: 'HIPAA BAA',
  addon_premiumGeoDB: 'Premium Geo DB',
  addon_premiumGeoDBOrg: 'Premium Geo DB',
}

export const BILLING_DEDICATED_DB_COMPUTE_CREDIT_RESOURCE_ID =
  'dedicatedDbComputeCredit'

export const BILLING_DEDICATED_DB_COMPUTE_CREDIT_LABEL =
  'Dedicated DB compute credit'

export type BillingAddonChargeRow = {
  resourceId: string
  name: string
  amount: number
  value: number
}

export function isBillingAddonResourceId(
  resourceId: string | null | undefined,
): boolean {
  return typeof resourceId === 'string' && resourceId.startsWith('addon_')
}

export function resolveBillingAddonDisplayName(resource: {
  resourceId: string
  name?: string | null
}): string {
  const fromApi = resource.name?.trim()
  if (fromApi) return fromApi
  return (
    BILLING_ADDON_NAME_FALLBACK[resource.resourceId] || resource.resourceId
  )
}

type AggregationResourceLike = {
  resourceId?: string
  name?: string | null
  desc?: string | null
  amount?: number | string | null
  value?: number | string | null
}

/**
 * Org-level toggle addons (BAA, Premium Geo DB, …) charged on the aggregation.
 * Seats/projects are handled separately in PlanSummary.
 */
export function getBillingAddonChargesFromResources(
  resources: AggregationResourceLike[] | null | undefined,
): BillingAddonChargeRow[] {
  if (!resources?.length) return []

  return resources
    .filter((resource) => {
      if (!isBillingAddonResourceId(resource.resourceId)) return false
      const amount = Number(resource.amount)
      return Number.isFinite(amount) && amount > 0
    })
    .map((resource) => {
      const resourceId = resource.resourceId as string
      return {
        resourceId,
        name: resolveBillingAddonDisplayName({
          resourceId,
          name: resource.name || resource.desc,
        }),
        amount: Number(resource.amount) || 0,
        value: Number(resource.value) || 0,
      }
    })
}

/**
 * Org-level dedicated DB compute credit (negative amount on the aggregation).
 */
export function getDedicatedDbComputeCreditFromResources(
  resources: AggregationResourceLike[] | null | undefined,
): BillingAddonChargeRow | null {
  if (!resources?.length) return null

  const credit = resources.find(
    (resource) =>
      resource.resourceId === BILLING_DEDICATED_DB_COMPUTE_CREDIT_RESOURCE_ID,
  )
  if (!credit) return null

  const amount = Number(credit.amount)
  if (!Number.isFinite(amount) || amount === 0) return null

  const name = BILLING_DEDICATED_DB_COMPUTE_CREDIT_LABEL

  return {
    resourceId: BILLING_DEDICATED_DB_COMPUTE_CREDIT_RESOURCE_ID,
    name,
    amount,
    value: Number(credit.value) || 0,
  }
}
