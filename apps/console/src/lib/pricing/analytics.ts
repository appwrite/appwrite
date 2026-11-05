import type { ComparisonTable, PlanId, PricingPlan } from './types'

/**
 * Analytics pricing is shown only while the `analytics` feature flag is on.
 * Keep in step with `analyticsEvents` in the cloud repo's app/config/plans.php
 * and the per-100K overage in app/config/plans/usage.php.
 */
export const ANALYTICS_COMPARISON_TABLE_TITLE = 'Analytics'

const ANALYTICS_PLAN_FEATURES: Partial<Record<PlanId, string>> = {
  free: '50K analytics events',
  start: '50K analytics events',
  pro: '100K analytics events',
}

/** Bullet the analytics allowance goes after, so it sits with the other usage limits. */
const ANALYTICS_FEATURE_ANCHOR = /monthly active users/i

export function withAnalyticsPlanFeatures(
  plans: readonly PricingPlan[],
  enabled: boolean,
): PricingPlan[] {
  if (!enabled) return [...plans]
  return plans.map((plan) => {
    const feature = ANALYTICS_PLAN_FEATURES[plan.id]
    if (!feature) return plan
    const features = [...plan.features]
    const anchor = features.findIndex((item) => ANALYTICS_FEATURE_ANCHOR.test(item))
    features.splice(anchor === -1 ? features.length : anchor + 1, 0, feature)
    return { ...plan, features }
  })
}

export function withAnalyticsComparison<T extends { title: string }>(
  tables: readonly T[],
  enabled: boolean,
): T[] {
  return enabled
    ? [...tables]
    : tables.filter((table) => table.title !== ANALYTICS_COMPARISON_TABLE_TITLE)
}

export type { ComparisonTable }
