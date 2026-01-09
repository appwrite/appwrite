import { Organization } from '@/lib/utils/mock-data'
import { getPlanBadgeColor as getPlanBadgeColorUtil, type PlanType } from '@/lib/utils/status-badge'

/**
 * Get the color classes for a plan badge
 * @param plan - The organization plan
 * @returns Tailwind CSS classes for the badge
 */
export function getPlanBadgeColor(plan: Organization['plan']): string {
  return getPlanBadgeColorUtil(plan as PlanType)
}

/**
 * Get the display name for a plan
 * @param plan - The organization plan
 * @returns The capitalized plan name
 */
export function getPlanDisplayName(plan: Organization['plan']): string {
  return plan.charAt(0).toUpperCase() + plan.slice(1)
}


