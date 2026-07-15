/**
 * Billing plan tier values used by the Appwrite Console API.
 * Replaces the removed BillingPlan enum from @appwrite.io/console.
 */
export const BillingPlanTier = {
  Tier0: 'tier-0',
  Tier1: 'tier-1',
  Tier2: 'tier-2',
} as const

export type BillingPlanTier =
  (typeof BillingPlanTier)[keyof typeof BillingPlanTier]

/** All valid billing plan tier string values */
export const BILLING_PLAN_TIER_VALUES: readonly string[] =
  Object.values(BillingPlanTier)
