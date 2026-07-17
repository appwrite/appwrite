import { subHours } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type { FirewallConditionDraft } from '@/lib/firewall/conditions'
import type { FirewallCreatableAction } from '@/lib/firewall/actions'
import { WafRuleAction } from '@appwrite.io/console'

export interface FirewallAnalytics {
  totalRequests: number
  totalDenied: number
  totalBypassed: number
  totalRateLimited: number
  totalRedirected: number
  requestsChange: number
  deniedChange: number
  bypassedChange: number
  rateLimitedChange: number
  redirectedChange: number
  denyRateChange: number
  timeSeries: Array<{
    timestamp: string
    requests: number
    denied: number
    bypassed: number
    rateLimited: number
    redirected: number
  }>
}

export type FirewallTrafficPoint = {
  date: string
  day: Date
  fullDate: string
  requests: number
  denied: number
  bypassed: number
  rateLimited: number
  redirected: number
}

export type FirewallImpactPoint = {
  date: string
  day: Date
  fullDate: string
  total: number
  matched: number
}

function generateTimeSeries() {
  const now = Date.now()
  const hours = 24
  const points: FirewallAnalytics['timeSeries'] = []

  for (let i = hours; i >= 0; i--) {
    const timestamp = new Date(now - i * 60 * 60 * 1000).toISOString()
    const phase = Math.sin(i * 0.45) * 0.5 + 0.5
    const baseRequests = 120 + phase * 220
    const denied = Math.floor(baseRequests * (0.08 + phase * 0.14))
    const rateLimited = Math.floor(baseRequests * (0.03 + phase * 0.06))
    const redirected = Math.floor(baseRequests * (0.01 + phase * 0.03))
    const bypassed = Math.floor(
      Math.max(0, baseRequests - denied - rateLimited - redirected),
    )

    points.push({
      timestamp,
      requests: Math.floor(baseRequests),
      denied,
      bypassed,
      rateLimited,
      redirected,
    })
  }

  return points
}

export const mockFirewallAnalytics: FirewallAnalytics = {
  totalRequests: 245680,
  totalDenied: 34250,
  totalBypassed: 198430,
  totalRateLimited: 9800,
  totalRedirected: 3200,
  requestsChange: 12.5,
  deniedChange: 8.3,
  bypassedChange: 15.2,
  rateLimitedChange: 4.1,
  redirectedChange: -1.8,
  denyRateChange: -3.2,
  timeSeries: generateTimeSeries(),
}

export function getDefaultFirewallRange() {
  const now = new Date()
  return { from: subHours(now, 24), to: now }
}

export function buildFirewallTrafficSeries(
  from: Date,
  to: Date,
): FirewallTrafficPoint[] {
  const spanMs = Math.max(to.getTime() - from.getTime(), 60 * 60 * 1000)
  const useHourly = spanMs <= 48 * 60 * 60 * 1000
  let count = Math.ceil(spanMs / (useHourly ? 60 * 60 * 1000 : 24 * 60 * 60 * 1000)) + 1
  count = Math.min(Math.max(count, 2), 150)
  const step = spanMs / (count - 1)
  const phase = from.getTime() / 1e12
  const rows: FirewallTrafficPoint[] = []

  for (let i = 0; i < count; i++) {
    const ts = new Date(from.getTime() + i * step)
    const u = (Math.sin(i * 2.9898 + phase) + 1) / 2
    const baseRequests = 100 + u * 200
    const denied = Math.floor(baseRequests * (0.1 + u * 0.2))
    const rateLimited = Math.floor(baseRequests * (0.04 + u * 0.08))
    const redirected = Math.floor(baseRequests * (0.02 + u * 0.04))
    const bypassed = Math.floor(
      Math.max(0, baseRequests - denied - rateLimited - redirected),
    )

    rows.push({
      date: useHourly
        ? formatLocalizedDate(ts, 'HH:mm')
        : formatLocalizedDate(ts, 'MMM d'),
      day: ts,
      fullDate: formatLocalizedDate(ts, 'MMM d, yyyy HH:mm'),
      requests: Math.floor(baseRequests),
      denied,
      bypassed,
      rateLimited,
      redirected,
    })
  }

  return rows
}

/**
 * Estimate how much recent traffic a draft rule would match.
 * Narrower conditions lower the match rate (mock until usage metrics exist).
 */
export function estimateRuleMatchRate(
  conditions: FirewallConditionDraft[],
  action: FirewallCreatableAction,
): number {
  const filled = conditions.filter((c) => c.value.trim().length > 0)
  let rate = filled.length === 0 ? 0.42 : 0.38

  for (const condition of filled) {
    const factor =
      condition.attribute === 'ip'
        ? 0.35
        : condition.attribute === 'path'
          ? 0.55
          : condition.attribute === 'country'
            ? 0.45
            : condition.attribute === 'method'
              ? 0.7
              : 0.6
    rate *= factor
  }

  if (action === WafRuleAction.RateLimit) rate *= 0.85
  if (action === WafRuleAction.Redirect) rate *= 0.55
  if (action === WafRuleAction.Bypass) rate *= 0.75

  return Math.min(0.95, Math.max(0.01, rate))
}

export function buildFirewallImpactSeries(
  matchRate: number,
): FirewallImpactPoint[] {
  const from = subHours(new Date(), 24)
  const to = new Date()
  const traffic = buildFirewallTrafficSeries(from, to)

  return traffic.map((point, index) => {
    const wobble = 0.85 + ((Math.sin(index * 1.7) + 1) / 2) * 0.3
    const matched = Math.floor(point.requests * matchRate * wobble)
    return {
      date: point.date,
      day: point.day,
      fullDate: point.fullDate,
      total: point.requests,
      matched: Math.min(point.requests, matched),
    }
  })
}

export function summarizeImpact(series: FirewallImpactPoint[]) {
  const total = series.reduce((sum, point) => sum + point.total, 0)
  const matched = series.reduce((sum, point) => sum + point.matched, 0)
  return {
    total,
    matched,
    rate: total > 0 ? matched / total : 0,
  }
}
