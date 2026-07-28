import type { FirewallTrafficPoint } from '@/lib/usage/firewall-events'

export const FIREWALL_TRAFFIC_SERIES = [
  {
    key: 'requests' as const,
    label: 'Passed',
    color: '#10b981',
    gradientId: 'firewall-requests-fill',
  },
  {
    key: 'denied' as const,
    label: 'Denied',
    color: 'var(--destructive)',
    gradientId: 'firewall-denied-fill',
  },
  {
    key: 'challenged' as const,
    label: 'Challenged',
    color: 'var(--chart-4)',
    gradientId: 'firewall-challenged-fill',
  },
  {
    key: 'rateLimited' as const,
    label: 'Rate limited',
    color: '#f59e0b',
    gradientId: 'firewall-rate-limited-fill',
  },
  {
    key: 'redirected' as const,
    label: 'Redirected',
    color: 'var(--chart-3)',
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

export function sortFirewallTrafficSeriesByValueDesc(
  totals: Record<FirewallTrafficSeriesKey, number>,
) {
  return [...FIREWALL_TRAFFIC_SERIES].sort((a, b) => {
    const diff = totals[b.key] - totals[a.key]
    if (diff !== 0) return diff
    return (
      FIREWALL_TRAFFIC_SERIES.findIndex((series) => series.key === a.key) -
      FIREWALL_TRAFFIC_SERIES.findIndex((series) => series.key === b.key)
    )
  })
}
