/**
 * Backup-policy caps from the organization billing plan.
 *
 * Same convention as databases, functions, buckets, and firewall rules:
 * - `0` / unset → unlimited
 * - `> 0` → hard cap; disable create when `count >= limit`
 *
 * Cloud may also send INT64_MAX (or other values at/above Number.MAX_SAFE_INTEGER)
 * as an “unlimited” sentinel. Treat those as unlimited (`0`).
 *
 * Plan numeric fields may arrive as bigint (SDK json-bigint / int64). Always
 * coerce to number before arithmetic with other number types.
 */

const MAX_SAFE = BigInt(Number.MAX_SAFE_INTEGER)

function toPlanLimitNumber(
  value: number | bigint | null | undefined,
): number {
  if (value == null) return 0

  if (typeof value === 'bigint') {
    // INT64_MAX and other huge sentinels mean unlimited
    if (value <= 0n || value >= MAX_SAFE) return 0
    return Number(value)
  }

  if (!Number.isFinite(value) || value <= 0 || value >= Number.MAX_SAFE_INTEGER) {
    return 0
  }

  return value
}

export function getBackupPoliciesPlanLimit(
  plan: { backupPolicies?: number | bigint | null } | null | undefined,
): number {
  return toPlanLimitNumber(plan?.backupPolicies)
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
