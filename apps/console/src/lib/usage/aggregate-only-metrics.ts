import type { Models } from '@appwrite.io/console'
import { BANDWIDTH_EVENT_METRICS } from '@/lib/usage/bandwidth-events'
import { REQUESTS_EVENT_METRICS } from '@/lib/usage/requests-events'

export type UsageAggregateOnlyPlan =
  | Pick<Models.BillingPlan, 'usageAggregateOnlyMetrics'>
  | null
  | undefined

export function getUsageAggregateOnlyMetrics(
  plan: UsageAggregateOnlyPlan,
): ReadonlySet<string> {
  return new Set(plan?.usageAggregateOnlyMetrics ?? [])
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
