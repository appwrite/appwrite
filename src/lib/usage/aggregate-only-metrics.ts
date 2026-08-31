import type { Models } from '@appwrite.io/console'
import { BANDWIDTH_EVENT_METRICS } from '@/lib/usage/bandwidth-events'
import { REQUESTS_EVENT_METRICS } from '@/lib/usage/requests-events'

/**
 * Plan field from billing API. Typed as an extension until the SDK pin
 * includes `usageAggregateOnlyMetrics` on `Models.BillingPlan`.
 */
export type BillingPlanAggregateOnlyMetricsFields = {
  usageAggregateOnlyMetrics?: string[]
}

export type UsageAggregateOnlyPlan =
  | (Models.BillingPlan & BillingPlanAggregateOnlyMetricsFields)
  | BillingPlanAggregateOnlyMetricsFields
  | null
  | undefined

export function getUsageAggregateOnlyMetrics(
  plan: UsageAggregateOnlyPlan,
): ReadonlySet<string> {
  const metrics =
    (plan as BillingPlanAggregateOnlyMetricsFields | null | undefined)
      ?.usageAggregateOnlyMetrics ?? []
  return new Set(metrics)
}

/** True when any of the given metrics are totals-only on this plan. */
export function hasUsageAggregateOnlyMetrics(
  plan: UsageAggregateOnlyPlan,
  metrics: readonly string[],
): boolean {
  if (metrics.length === 0) return false
  const aggregateOnly = getUsageAggregateOnlyMetrics(plan)
  if (aggregateOnly.size === 0) return false
  return metrics.some((metric) => aggregateOnly.has(metric))
}

export function canShowRequestsUsageBreakdown(
  plan: UsageAggregateOnlyPlan,
): boolean {
  return !hasUsageAggregateOnlyMetrics(plan, REQUESTS_EVENT_METRICS)
}

export function canShowBandwidthUsageBreakdown(
  plan: UsageAggregateOnlyPlan,
): boolean {
  return !hasUsageAggregateOnlyMetrics(plan, BANDWIDTH_EVENT_METRICS)
}

/**
 * Breakdowns and filters both require event-level data. Aggregate-only metrics
 * only have a daily total, so neither is available for these categories.
 */
export function canShowUsageCategoryBreakdowns(
  categoryId: string,
  plan: UsageAggregateOnlyPlan,
): boolean {
  if (categoryId === 'requests') return canShowRequestsUsageBreakdown(plan)
  if (categoryId === 'bandwidth') return canShowBandwidthUsageBreakdown(plan)
  return true
}

export function canShowUsageCategoryFilters(
  categoryId: string,
  plan: UsageAggregateOnlyPlan,
): boolean {
  return canShowUsageCategoryBreakdowns(categoryId, plan)
}
