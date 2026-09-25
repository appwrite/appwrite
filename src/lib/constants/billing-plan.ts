/**
 * Billing plan tier values used by the Appwrite Console API.
 * Replaces the removed BillingPlan enum from @appwrite.io/console.
 */
export const BillingPlanTier = {
  Tier0: 'tier-0',
  /** Regional Start plan (India and Nepal). Must be matched before `tier-1`. */
  Start: 'tier-1-1',
  Tier1: 'tier-1',
  Tier2: 'tier-2',
} as const

export type BillingPlanTier =
  (typeof BillingPlanTier)[keyof typeof BillingPlanTier]

/** All valid billing plan tier string values */
export const BILLING_PLAN_TIER_VALUES: readonly string[] =
  Object.values(BillingPlanTier)
