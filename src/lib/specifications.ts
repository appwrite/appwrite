/**
 * Helpers for plan-limited specifications (sites and functions).
 *
 * APIs return specifications with an `enabled` field; when `enabled === false`,
 * that option is not available in the current plan. Use these helpers everywhere
 * we show specification options so we consistently limit/display only allowed options.
 */

/**
 * Specification list type for `functions.listSpecifications` / `sites.listSpecifications`.
 * SDK types this as string; API accepts `runtimes` or `builds`.
 */
export const SpecificationType = {
  Runtimes: 'runtimes',
  Builds: 'builds',
} as const

export type SpecificationType =
  (typeof SpecificationType)[keyof typeof SpecificationType]

export type SpecificationWithPlan = {
  enabled?: boolean
  slug?: string
  [key: string]: unknown
}

/**
 * Returns specifications that are allowed in the current plan (enabled !== false).
 * Use this when you need a list of only selectable options (e.g. default selection).
 */
export function getSpecificationsAllowedInPlan<T extends SpecificationWithPlan>(
  specifications: T[],
): T[] {
  return specifications.filter((spec) => spec.enabled !== false)
}

/**
 * Returns the first specification allowed in the current plan, or undefined.
 * Use for default selection when opening a spec picker (e.g. runtime limits dialog).
 */
export function getFirstEnabledSpecification<T extends SpecificationWithPlan>(
  specifications: T[],
): T | undefined {
  return specifications.find((spec) => spec.enabled !== false)
}

/**
 * Returns true if any specification is not allowed in the current plan (enabled === false).
 * Use to show "Upgrade your plan" messaging when there are locked options.
 */
export function hasUnavailableSpecifications<T extends SpecificationWithPlan>(
  specifications: T[],
): boolean {
  return specifications.some((spec) => spec.enabled === false)
}

/**
 * Returns true if the given specification is allowed in the current plan.
 */
export function isSpecificationAllowedInPlan(
  spec: SpecificationWithPlan,
): boolean {
  return spec.enabled !== false
}
