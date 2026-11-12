import type { CSSProperties } from 'react'
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
 * Inline colors for plans that need a specific hue. Start is orange.
 */
export function getPlanBadgeStyle(
  plan: Organization['plan'],
): CSSProperties | undefined {
  if (plan !== 'start') return undefined
  return {
    backgroundColor: 'color-mix(in srgb, #f97316 12%, transparent)',
    color: 'light-dark(#c2410c, #fdba74)',
  }
}

/**
 * Display label for a canonical org plan (same rules as {@link getCanonicalPlanDisplayLabel}).
 */
export function getPlanDisplayName(plan: Organization['plan']): string {
  return getCanonicalPlanDisplayLabel(plan)
}
