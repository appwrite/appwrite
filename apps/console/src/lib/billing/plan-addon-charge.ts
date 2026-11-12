import type { Models } from '@appwrite.io/console'

export type BillablePlanAddonKey = 'projects' | 'seats'

type BillingPlan = Models.BillingPlan

function getAddon(
  plan: BillingPlan | null | undefined,
  key: BillablePlanAddonKey,
) {
  return plan?.addons?.[key]
}

/**
 * Included count before per-unit addon charges apply (plan field or addon.planIncluded).
 */
export function getPlanAddonIncludedCount(
  plan: BillingPlan | null | undefined,
  key: BillablePlanAddonKey,
): number | null {
  if (!plan) return null

  if (key === 'projects') {
    const fromPlan = plan.projects
    if (
      fromPlan !== undefined &&
      fromPlan !== null &&
      !Number.isNaN(Number(fromPlan)) &&
      Number(fromPlan) > 0
    ) {
      return Number(fromPlan)
    }
  }

  if (key === 'seats') {
    const fromPlan = plan.members
    if (
      fromPlan !== undefined &&
      fromPlan !== null &&
      !Number.isNaN(Number(fromPlan)) &&
      Number(fromPlan) > 0
    ) {
      return Number(fromPlan)
    }
  }

  const addon = getAddon(plan, key)
  const planIncluded = addon?.planIncluded
  if (
    planIncluded !== undefined &&
    planIncluded !== null &&
    !Number.isNaN(Number(planIncluded))
  ) {
    return Number(planIncluded)
  }

  return null
}

export function getPlanAddonPrice(
  plan: BillingPlan | null | undefined,
  key: BillablePlanAddonKey,
): number {
  const addon = getAddon(plan, key)
  const price = addon?.price
  if (price === undefined || price === null || Number.isNaN(Number(price))) {
    return 0
  }
  return Number(price)
}

/**
 * Hard cap when addon purchases are not supported (0 = unlimited).
 */
export function getPlanAddonHardLimit(
  plan: BillingPlan | null | undefined,
  key: BillablePlanAddonKey,
): number | null {
  if (!plan) return null

  const addon = getAddon(plan, key)
  const limit = addon?.limit
  if (limit === undefined || limit === null || Number.isNaN(Number(limit))) {
    return null
  }

  const num = Number(limit)
  if (num <= 0) return null
  return num
}

/**
 * Returns per-unit monthly price when creating `additionalCount` more items would exceed plan included allocation.
 */
export function wouldIncurPlanAddonCharge(
  plan: BillingPlan | null | undefined,
  key: BillablePlanAddonKey,
  currentCount: number,
  additionalCount = 1,
): { pricePerMonth: number; currency: string } | null {
  const price = getPlanAddonPrice(plan, key)
  if (price <= 0) return null

  const included = getPlanAddonIncludedCount(plan, key)
  if (included === null) return null

  if (currentCount + additionalCount <= included) return null

  const addon = getAddon(plan, key)
  return {
    pricePerMonth: price,
    currency: addon?.currency || 'USD',
  }
}
