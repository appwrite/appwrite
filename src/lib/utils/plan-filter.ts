/** Education / sponsored tier id from billing */
const AUTO_EDUCATION_PLAN = /^auto-1$/i

/**
 * Normalized plan id used across the console (badges, limits, org list).
 * Derived from billing tier strings via {@link getPlanNameFromTier}.
 */
export type CanonicalPlanId = 'free' | 'pro' | 'custom' | 'education'

/**
 * Maps tier values to canonical plan ids
 * - tier-0 → 'free'
 * - tier-1 → 'pro'
 * - auto-1 → 'education'
 * - anything else → 'custom'
 */
export function getPlanNameFromTier(
  tier: string | number | null | undefined,
): CanonicalPlanId {
  if (tier === null || tier === undefined) {
    return 'free'
  }

  if (typeof tier === 'string') {
    if (AUTO_EDUCATION_PLAN.test(tier.trim())) {
      return 'education'
    }

    const tierMatch = tier.match(/tier-(\d+)/i)
    if (tierMatch) {
      const tierNumber = parseInt(tierMatch[1], 10)
      if (tierNumber === 0) return 'free'
      if (tierNumber === 1) return 'pro'
      return 'custom'
    }

    if (tier === '0' || tier.toLowerCase() === 'tier-0') return 'free'
    if (tier === '1' || tier.toLowerCase() === 'tier-1') return 'pro'

    const normalized = tier.toLowerCase()
    if (['free', 'pro', 'custom', 'education'].includes(normalized)) {
      return normalized as CanonicalPlanId
    }
    if (['scale', 'enterprise'].includes(normalized)) return 'custom'

    return 'custom'
  }

  if (typeof tier === 'number') {
    if (tier === 0) return 'free'
    if (tier === 1) return 'pro'
    return 'custom'
  }

  return 'free'
}

/**
 * Title-case label for a canonical plan id (badges, headings, comparisons).
 * Single source of truth for plan display names from normalized ids.
 */
export function getCanonicalPlanDisplayLabel(plan: CanonicalPlanId): string {
  switch (plan) {
    case 'free':
      return 'Free'
    case 'pro':
      return 'Pro'
    case 'education':
      return 'Education'
    case 'custom':
    default:
      return 'Custom'
  }
}

/**
 * Display label for a raw billing tier string (e.g. from API or plan picker value).
 * Normalizes with {@link getPlanNameFromTier}, then {@link getCanonicalPlanDisplayLabel}.
 */
export function getBillingPlanDisplayLabel(
  tier: string | number | null | undefined,
): string {
  return getCanonicalPlanDisplayLabel(getPlanNameFromTier(tier))
}

export type ResolveOrganizationPlanLabelInput = {
  /** Organization `billingPlan` from teams API (e.g. tier-0, auto-1) */
  billingPlan?: string | null
  /** Plan record `name` from `organizations.getPlan` */
  planName?: string | null
  /** Plan record `$id` */
  planId?: string | null
}

function isAutoEducationPlanRef(
  id: string,
  billing: string,
  name: string,
): boolean {
  return (
    AUTO_EDUCATION_PLAN.test(id) ||
    AUTO_EDUCATION_PLAN.test(billing) ||
    AUTO_EDUCATION_PLAN.test(name)
  )
}

/**
 * Human-readable plan name for UI when you have org billing + optional plan record fields.
 * Handles API ids/names like `auto-1` and falls back to tier-based labels.
 */
export function resolveOrganizationPlanDisplayLabel(
  input: ResolveOrganizationPlanLabelInput,
): string {
  const id = input.planId?.trim() ?? ''
  const billing = input.billingPlan?.trim() ?? ''
  const name = input.planName?.trim() ?? ''

  if (isAutoEducationPlanRef(id, billing, name)) {
    return getCanonicalPlanDisplayLabel('education')
  }
  if (name.length > 0) {
    return input.planName as string
  }
  return getBillingPlanDisplayLabel(input.billingPlan)
}
