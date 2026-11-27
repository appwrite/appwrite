import type { ComparisonTable, PlanId, PricingPlan } from './types'

/**
 * Analytics plan bullets are shown only while the `analytics` feature flag is
 * on (the comparison table is gated in ./flags).
 * Keep in step with `analyticsEvents` in the cloud repo's app/config/plans.php
 * and the per-100K overage in app/config/plans/usage.php.
 */
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

export type { ComparisonTable }
