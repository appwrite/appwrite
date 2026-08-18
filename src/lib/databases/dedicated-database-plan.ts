import type { Models } from '@appwrite.io/console'
import { getBillingPlanResourceLimit } from '@/lib/billing/project-breakdown-resources'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { formatCompactCount } from '@/lib/usage/format-metric'

/**
 * Plan fields for dedicated databases. Typed as an extension until every SDK
 * pin includes them on `Models.BillingPlan`.
 */
export type BillingPlanDedicatedDatabaseFields = {
  supportsDedicatedDatabases?: boolean
  databaseComputeCredit?: number | bigint
}

export type PlanDatabaseOperationLimit = number | 'unlimited'

export type PlanDatabaseOperationLimits = {
  reads: PlanDatabaseOperationLimit | null
  writes: PlanDatabaseOperationLimit | null
}

export type PlanDatabaseOperationOverageRate = {
  priceUsd: number
  perOps: number
  currency: string
}

export type PlanDatabaseOperationOverage = {
  reads: PlanDatabaseOperationOverageRate | null
  writes: PlanDatabaseOperationOverageRate | null
}

type PlanUsageRateEntry = {
  price?: number
  value?: number
  currency?: string
}

function readDedicatedDatabaseFields(
  plan: Models.BillingPlan | null | undefined,
): BillingPlanDedicatedDatabaseFields {
  if (!plan) return {}
  return plan as Models.BillingPlan & BillingPlanDedicatedDatabaseFields
}

function toCreditUsd(value: number | bigint | null | undefined): number {
  if (value == null) return 0
  if (typeof value === 'bigint') {
    if (value <= 0n) return 0
    return Number(value)
  }
  if (!Number.isFinite(value) || value <= 0) return 0
  return value
}

function normalizeOperationLimit(
  value: number | null,
): PlanDatabaseOperationLimit | null {
  if (value == null) return null
  if (value <= 0 || value >= Number.MAX_SAFE_INTEGER) return 'unlimited'
  return value
}

function readUsageRate(
  plan: Models.BillingPlan | null | undefined,
  usageKey: string,
): PlanDatabaseOperationOverageRate | null {
  if (!plan) return null
  const usage = plan.usage as
    | Record<string, PlanUsageRateEntry | undefined>
    | undefined
  const entry = usage?.[usageKey]
  if (!entry) return null

  const priceUsd = Number(entry.price)
  if (!Number.isFinite(priceUsd) || priceUsd <= 0) return null

  let perOps = Number(entry.value)
  if (!Number.isFinite(perOps) || perOps <= 0) {
    perOps = 100_000
  }

  // Per-operation rates are tiny; show the familiar per-100k price instead.
  if (perOps === 1 && priceUsd < 0.01) {
    return {
      priceUsd: priceUsd * 100_000,
      perOps: 100_000,
      currency: entry.currency?.trim() || 'USD',
    }
  }

  return {
    priceUsd,
    perOps,
    currency: entry.currency?.trim() || 'USD',
  }
}

/**
 * Whether the organization plan allows dedicated databases.
 * `null` while the plan has not loaded yet (billing-enabled consoles only).
 */
export function planSupportsDedicatedDatabases(
  plan: Models.BillingPlan | null | undefined,
): boolean | null {
  const features = getActiveProfileFeatures()
  if (!features.billing) {
    return features.dedicatedDbsSupport
  }
  if (plan == null) return null
  return readDedicatedDatabaseFields(plan).supportsDedicatedDatabases === true
}

/** Monthly dedicated-database compute credit included in the plan, in USD. */
export function getPlanDatabaseComputeCreditUsd(
  plan: Models.BillingPlan | null | undefined,
): number {
  return toCreditUsd(readDedicatedDatabaseFields(plan).databaseComputeCredit)
}

/** Monthly serverless read/write quotas included in the plan. */
export function getPlanDatabaseOperationLimits(
  plan: Models.BillingPlan | null | undefined,
): PlanDatabaseOperationLimits {
  if (!plan) {
    return { reads: null, writes: null }
  }
  return {
    reads: normalizeOperationLimit(
      getBillingPlanResourceLimit(plan, 'databaseReads'),
    ),
    writes: normalizeOperationLimit(
      getBillingPlanResourceLimit(plan, 'databaseWrites'),
    ),
  }
}

/** Overage rates for extra serverless reads/writes. Absent on Free (price 0). */
export function getPlanDatabaseOperationOverage(
  plan: Models.BillingPlan | null | undefined,
): PlanDatabaseOperationOverage {
  return {
    reads: readUsageRate(plan, 'databasesReads'),
    writes: readUsageRate(plan, 'databasesWrites'),
  }
}

export function formatDatabaseOperationOverageRate(
  rate: PlanDatabaseOperationOverageRate,
): string {
  const digits = rate.priceUsd > 0 && rate.priceUsd < 0.1 ? 3 : 2
  const price = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: rate.currency || 'USD',
    minimumFractionDigits: digits,
    maximumFractionDigits: 3,
  }).format(rate.priceUsd)
  return `${price} / ${formatCompactCount(rate.perOps)}`
}
