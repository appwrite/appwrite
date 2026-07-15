/** Education / sponsored tier id from billing */
const AUTO_EDUCATION_PLAN = /^auto-1$/i

/**
 * Normalized plan id used across the console (badges, limits, org list).
 * Derived from billing tier strings via {@link getPlanNameFromTier}.
 */
export type CanonicalPlanId =
  | 'free'
  | 'pro'
  | 'core'
  | 'custom'
  | 'education'

/**
 * Maps tier values to canonical plan ids
 * - tier-0 → 'free'
 * - tier-1 → 'pro'
 * - tier-2 → 'core' (legacy API name: Scale)
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
      if (tierNumber === 2) return 'core'
      return 'custom'
    }

    if (tier === '0' || tier.toLowerCase() === 'tier-0') return 'free'
    if (tier === '1' || tier.toLowerCase() === 'tier-1') return 'pro'
    if (tier === '2' || tier.toLowerCase() === 'tier-2') return 'core'

    const normalized = tier.toLowerCase()
    if (['free', 'pro', 'core', 'custom', 'education'].includes(normalized)) {
      return normalized as CanonicalPlanId
    }
    // Legacy Scale plan id/name → Core
    if (normalized === 'scale') return 'core'
    if (normalized === 'enterprise') return 'custom'

    return 'custom'
  }

  if (typeof tier === 'number') {
    if (tier === 0) return 'free'
    if (tier === 1) return 'pro'
    if (tier === 2) return 'core'
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
    case 'core':
      return 'Core'
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

export type BillingPlanRecord = {
  $id?: string
  name?: string
  order?: number
  price?: number
}

function canonicalRank(plan: CanonicalPlanId): number {
  if (plan === 'free') return 0
  if (plan === 'pro' || plan === 'education') return 1
  if (plan === 'core') return 2
  return 3
}

/**
 * Resolve a billing plan record from a plan ref (billingPlans map key or $id).
 */
export function resolveBillingPlanRecord(
  planRef: string | null | undefined,
  plans: Record<string, BillingPlanRecord> | null | undefined,
): BillingPlanRecord | null {
  if (!planRef || !plans) return null
  if (plans[planRef]) return plans[planRef]
  return Object.values(plans).find((plan) => plan.$id === planRef) ?? null
}

/**
 * Canonical plan id from a billing plan ref and optional plan catalog.
 * Uses plan record fields when available, then falls back to tier parsing.
 */
export function getPlanCanonicalFromRecord(
  planRef: string | null | undefined,
  plans: Record<string, BillingPlanRecord> | null | undefined,
): CanonicalPlanId {
  const plan = resolveBillingPlanRecord(planRef, plans)
  if (plan?.name) {
    const name = plan.name.toLowerCase()
    if (name.includes('free') || name === 'starter') return 'free'
    if (name.includes('pro')) return 'pro'
    // Core (current) and Scale (legacy tier-2 name)
    if (name.includes('core') || name.includes('scale')) return 'core'
    if (name.includes('education') || name.includes('sponsored')) {
      return 'education'
    }
  }

  if (plan && typeof plan.order === 'number') {
    if (plan.order <= 0 && (plan.price ?? 0) === 0) return 'free'
    if (plan.order === 1) return 'pro'
    if (plan.order === 2) return 'core'
  }

  return getPlanNameFromTier(plan?.$id ?? planRef)
}

export function isFreePlanRef(
  planRef: string | null | undefined,
  plans: Record<string, BillingPlanRecord> | null | undefined,
): boolean {
  return getPlanCanonicalFromRecord(planRef, plans) === 'free'
}

export function compareBillingPlanRefs(
  currentRef: string | null | undefined,
  selectedRef: string | null | undefined,
  plans: Record<string, BillingPlanRecord> | null | undefined,
): 'upgrade' | 'downgrade' | 'same' | 'unknown' {
  if (!currentRef || !selectedRef) return 'unknown'
  if (currentRef === selectedRef) return 'same'

  const currentPlan = resolveBillingPlanRecord(currentRef, plans)
  const selectedPlan = resolveBillingPlanRecord(selectedRef, plans)

  if (
    currentPlan &&
    selectedPlan &&
    typeof currentPlan.order === 'number' &&
    typeof selectedPlan.order === 'number' &&
    currentPlan.order !== selectedPlan.order
  ) {
    return selectedPlan.order < currentPlan.order ? 'downgrade' : 'upgrade'
  }

  if (
    currentPlan &&
    selectedPlan &&
    typeof currentPlan.price === 'number' &&
    typeof selectedPlan.price === 'number' &&
    currentPlan.price !== selectedPlan.price
  ) {
    return selectedPlan.price < currentPlan.price ? 'downgrade' : 'upgrade'
  }

  const currentRank = canonicalRank(getPlanCanonicalFromRecord(currentRef, plans))
  const selectedRank = canonicalRank(
    getPlanCanonicalFromRecord(selectedRef, plans),
  )

  if (selectedRank < currentRank) return 'downgrade'
  if (selectedRank > currentRank) return 'upgrade'
  return 'unknown'
}
