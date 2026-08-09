import { WafRuleAction } from '@appwrite.io/console'
import {
  FIREWALL_PASSED_CHART_COLOR,
  getFirewallActionChartColor,
} from '@/lib/firewall/actions'
import type { FirewallTrafficPoint } from '@/lib/usage/firewall-events'

// Colors come from the shared firewall action palette so the chart matches
// action dots and badges everywhere (see FIREWALL_ACTION_COLORS in actions.ts).
export const FIREWALL_TRAFFIC_SERIES = [
  {
    key: 'requests' as const,
    label: 'Passed',
    color: FIREWALL_PASSED_CHART_COLOR,
    gradientId: 'firewall-requests-fill',
  },
  {
    key: 'denied' as const,
    label: 'Denied',
    color: getFirewallActionChartColor(WafRuleAction.Deny),
    gradientId: 'firewall-denied-fill',
  },
  {
    key: 'challenged' as const,
    label: 'Challenged',
    color: getFirewallActionChartColor(WafRuleAction.Challenge),
    gradientId: 'firewall-challenged-fill',
  },
  {
    key: 'rateLimited' as const,
    label: 'Rate limited',
    color: getFirewallActionChartColor(WafRuleAction.RateLimit),
    gradientId: 'firewall-rate-limited-fill',
  },
  {
    key: 'redirected' as const,
    label: 'Redirected',
    color: getFirewallActionChartColor(WafRuleAction.Redirect),
    gradientId: 'firewall-redirected-fill',
  },
] as const

export type FirewallTrafficSeriesKey =
  (typeof FIREWALL_TRAFFIC_SERIES)[number]['key']

export function getFirewallTrafficSeriesTotals(
  points: readonly FirewallTrafficPoint[],
): Record<FirewallTrafficSeriesKey, number> {
  return points.reduce(
    (totals, point) => ({
      requests: totals.requests + point.requests,
      denied: totals.denied + point.denied,
      challenged: totals.challenged + point.challenged,
      rateLimited: totals.rateLimited + point.rateLimited,
      redirected: totals.redirected + point.redirected,
    }),
    {
      requests: 0,
      denied: 0,
      challenged: 0,
      rateLimited: 0,
      redirected: 0,
    },
  )
}

export function sortFirewallTrafficSeriesByValueAsc(
  totals: Record<FirewallTrafficSeriesKey, number>,
) {
  return [...FIREWALL_TRAFFIC_SERIES].sort((a, b) => {
    const diff = totals[a.key] - totals[b.key]
    if (diff !== 0) return diff
    return (
      FIREWALL_TRAFFIC_SERIES.findIndex((series) => series.key === a.key) -
      FIREWALL_TRAFFIC_SERIES.findIndex((series) => series.key === b.key)
    )
  })
}
