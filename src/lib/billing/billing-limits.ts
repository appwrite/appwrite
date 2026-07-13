/**
 * Helpers for organization/project `billingLimits` from Cloud aggregation.
 *
 * Cloud stores percentage-of-plan usage for resources that crossed 100%
 * (see Aggregator::initializePlanResourceMax + TeamAggregation::sendUsageAlerts).
 * `budgetLimit` is a separate paid-plan budget-cap signal (percentage of the
 * configured dollar budget), handled by {@link isBudgetLimitReached}.
 */

/** Resource keys Cloud may put on `billingLimits` when usage >= 100%. */
export const BILLING_LIMIT_RESOURCE_LABELS = {
  bandwidth: 'Bandwidth',
  storage: 'Storage',
  users: 'Users',
  executions: 'Executions',
  GBHours: 'GB-hours',
  imageTransformations: 'Image transformations',
  authPhone: 'Phone auth',
  databasesWrites: 'Database writes',
  databasesReads: 'Database reads',
  screenshotsGenerated: 'Screenshots',
  realtime: 'Realtime connections',
  realtimeMessages: 'Realtime messages',
  /** Legacy key still checked by older console tooling */
  documents: 'Documents',
} as const

export type BillingLimitResourceKey = keyof typeof BILLING_LIMIT_RESOURCE_LABELS

const BUDGET_LIMIT_KEY = 'budgetLimit'
const USAGE_LIMIT_THRESHOLD = 100

export type BillingLimitsMap = Record<string, number | string | null | undefined>

function toFiniteNumber(value: unknown): number | null {
  if (value == null || value === '') return null
  const n = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(n) ? n : null
}

/**
 * Keys in `billingLimits` (excluding budget) whose value is at or above 100%.
 */
export function getReachedPlanUsageLimitKeys(
  billingLimits: BillingLimitsMap | null | undefined,
): string[] {
  if (!billingLimits || typeof billingLimits !== 'object') return []

  return Object.entries(billingLimits)
    .filter(([key, value]) => {
      if (key === BUDGET_LIMIT_KEY) return false
      const n = toFiniteNumber(value)
      return n != null && n >= USAGE_LIMIT_THRESHOLD
    })
    .map(([key]) => key)
}

/**
 * True when a resource has one or more plan usage limits at or above 100%
 * (free/starter/education overage). Does not include budget-cap blocks.
 */
export function isPlanUsageLimitReached(
  resource:
    | {
        billingLimits?: BillingLimitsMap | null
      }
    | null
    | undefined,
): boolean {
  return getReachedPlanUsageLimitKeys(resource?.billingLimits).length > 0
}

/**
 * Display label for a Cloud billing-limit resource key, or null if unknown.
 */
export function getBillingLimitResourceLabel(
  key: string,
): string | null {
  if (key in BILLING_LIMIT_RESOURCE_LABELS) {
    return BILLING_LIMIT_RESOURCE_LABELS[key as BillingLimitResourceKey]
  }
  return null
}

/**
 * Pick a single recognized resource label for messaging.
 * Returns null when none, multiple, or unrecognized (caller should use generic copy).
 */
export function getSingleRecognizedPlanUsageLimitLabel(
  billingLimits: BillingLimitsMap | null | undefined,
): string | null {
  const keys = getReachedPlanUsageLimitKeys(billingLimits)
  if (keys.length !== 1) return null
  return getBillingLimitResourceLabel(keys[0]!)
}
