/**
 * Backup-policy caps from the organization billing plan.
 *
 * Same convention as databases, functions, buckets, and firewall rules:
 * - `0` / unset → unlimited
 * - `> 0` → hard cap; disable create when `count >= limit`
 */

export function getBackupPoliciesPlanLimit(
  plan: { backupPolicies?: number | null } | null | undefined,
): number {
  return plan?.backupPolicies ?? 0
}

export function isBackupPoliciesAtPlanLimit(
  currentCount: number,
  limit: number,
): boolean {
  return limit > 0 && currentCount >= limit
}

/** Remaining slots under the plan cap, or `null` when unlimited. */
export function getBackupPoliciesRemainingSlots(
  currentCount: number,
  limit: number,
): number | null {
  if (limit <= 0) return null
  return Math.max(0, limit - currentCount)
}

/**
 * Hourly presets and custom schedules require more than the Pro daily-only tier.
 * Unlimited (`0`) or limits above 1 unlock advanced policies.
 */
export function supportsAdvancedBackupPolicies(limit: number): boolean {
  return limit === 0 || limit > 1
}
