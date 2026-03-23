import { Organization } from '@/lib/utils/mock-data'
import { getCanonicalPlanDisplayLabel } from '@/lib/utils/plan-filter'
import {
  getPlanBadgeColor as getPlanBadgeColorUtil,
  type PlanType,
} from '@/lib/utils/status-badge'

/**
 * Get the color classes for a plan badge
 * @param plan - The organization plan
 * @returns Tailwind CSS classes for the badge
 */
export function getPlanBadgeColor(plan: Organization['plan']): string {
  return getPlanBadgeColorUtil(plan as PlanType)
}

/**
 * Display label for a canonical org plan (same rules as {@link getCanonicalPlanDisplayLabel}).
 */
export function getPlanDisplayName(plan: Organization['plan']): string {
  return getCanonicalPlanDisplayLabel(plan)
}
