import { normalizeCountryCode } from '@/lib/pricing/start-plan'
import {
  resolveOrganizationCanonicalPlan,
  type CanonicalPlanId,
} from '@/lib/utils/plan-filter'

const PAYING_CANONICAL_PLANS = new Set<CanonicalPlanId>([
  'start',
  'pro',
  'core',
  'custom',
  'education',
])

type OrganizationBillingFields = {
  billingPlan?: string | null
  billingPlanId?: string | null
}

export function isIndiaCountryCode(
  countryCode: string | null | undefined,
): boolean {
  return normalizeCountryCode(countryCode) === 'IN'
}

export function isNonPayingConsoleCustomer(
  teams: readonly OrganizationBillingFields[] | null | undefined,
): boolean {
  if (!teams?.length) return true
  return teams.every((team) => {
    const canonical = resolveOrganizationCanonicalPlan({
      billingPlan: team.billingPlan,
      billingPlanId: team.billingPlanId,
    })
    return !PAYING_CANONICAL_PLANS.has(canonical)
  })
}
