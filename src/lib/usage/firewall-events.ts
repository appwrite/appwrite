import type { DateRange } from 'react-day-picker'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  computeChangePercent,
  fetchUsageMetricsChartSeriesByMetric,
  sumUsageChartPoints,
  type UsageChartInterval,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'
import { REQUESTS_EVENT_METRICS } from '@/lib/usage/requests-events'

/** Total requests + Firewall decision event metrics. */
export const FIREWALL_TRAFFIC_EVENT_METRICS = [
  ...REQUESTS_EVENT_METRICS,
  'waf.requests.denied',
  'waf.requests.challenged',
  'waf.requests.rateLimited',
  'waf.requests.redirected',
  'waf.requests.challengeSolved',
  'waf.challenge.solveTimeMs'
] as const

export const FIREWALL_DENIED_METRIC = 'waf.requests.denied'
export const FIREWALL_CHALLENGED_METRIC = 'waf.requests.challenged'
export const FIREWALL_RATE_LIMITED_METRIC = 'waf.requests.rateLimited'
export const FIREWALL_REDIRECTED_METRIC = 'waf.requests.redirected'
export const FIREWALL_CHALLENGE_SOLVED_METRIC = 'waf.requests.challengeSolved'
export const FIREWALL_CHALLENGE_SOLVE_TIME_METRIC = 'waf.challenge.solveTimeMs'
export const FIREWALL_REQUESTS_METRIC = REQUESTS_EVENT_METRICS[0]

export type FirewallTrafficPoint = {
  date: string
  day: Date
  fullDate: string
  requests: number
  denied: number
  challenged: number
  rateLimited: number
  redirected: number
}

export interface ProjectFirewallTrafficOverview {
  totalRequests: number
  totalPassed: number
  totalDenied: number
  totalChallenged: number
  totalRateLimited: number
  totalRedirected: number
  totalChallengeSolved: number
  /** Average challenge solve time in ms (solveTimeMs total / challengeSolved total). */
  avgSolveTimeMs: number
  requestsChange: number
  passedChange: number
  deniedChange: number
  challengedChange: number
  rateLimitedChange: number
  redirectedChange: number
  challengeSolvedChange: number
  avgSolveTimeChange: number
  blockRateChange: number
  chartPoints: FirewallTrafficPoint[]
}

function changeFor(
  currentPoints: UsageChartPoint[],
  previousPoints: UsageChartPoint[],
): { total: number; change: number } {
  const total = sumUsageChartPoints(currentPoints)
  const previous = sumUsageChartPoints(previousPoints)
  return { total, change: computeChangePercent(total, previous) }
}

function mergeFirewallTrafficPoints(
  requests: UsageChartPoint[],
  denied: UsageChartPoint[],
  challenged: UsageChartPoint[],
  rateLimited: UsageChartPoint[],
  redirected: UsageChartPoint[],
): FirewallTrafficPoint[] {
  const base =
    requests.length > 0
      ? requests
      : denied.length > 0
        ? denied
        : challenged.length > 0
          ? challenged
          : rateLimited.length > 0
            ? rateLimited
            : redirected

  const deniedByTime = new Map(
    denied.map((point) => [point.day.getTime(), point.total]),
  )
  const challengedByTime = new Map(
    challenged.map((point) => [point.day.getTime(), point.total]),
  )
  const rateLimitedByTime = new Map(
    rateLimited.map((point) => [point.day.getTime(), point.total]),
  )
  const redirectedByTime = new Map(
    redirected.map((point) => [point.day.getTime(), point.total]),
  )
  const requestsByTime = new Map(
    requests.map((point) => [point.day.getTime(), point.total]),
  )

  return base.map((point) => {
    const time = point.day.getTime()
    return {
      date: point.date,
      day: point.day,
      fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
      requests: requestsByTime.get(time) ?? (base === requests ? point.total : 0),
      denied: deniedByTime.get(time) ?? (base === denied ? point.total : 0),
      challenged:
        challengedByTime.get(time) ?? (base === challenged ? point.total : 0),
      rateLimited:
        rateLimitedByTime.get(time) ??
        (base === rateLimited ? point.total : 0),
      redirected:
        redirectedByTime.get(time) ?? (base === redirected ? point.total : 0),
    }
  })
}

/**
 * Firewall traffic chart: passed (network.requests) + waf.requests.{denied,challenged,rateLimited,redirected}.
 * Top total is the sum of those series (no extra API call).
 */
export async function fetchProjectFirewallTrafficOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): Promise<ProjectFirewallTrafficOverview> {
  if (!projectId) {
    return emptyFirewallTrafficOverview()
  }

  const seriesByMetric = await fetchUsageMetricsChartSeriesByMetric(
    projectId,
    FIREWALL_TRAFFIC_EVENT_METRICS,
    dateRange,
    interval,
    undefined,
    logRetentionHours,
  )

  const requestsSeries = seriesByMetric.get(FIREWALL_REQUESTS_METRIC) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const deniedSeries = seriesByMetric.get(FIREWALL_DENIED_METRIC) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const challengedSeries = seriesByMetric.get(FIREWALL_CHALLENGED_METRIC) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const rateLimitedSeries = seriesByMetric.get(
    FIREWALL_RATE_LIMITED_METRIC,
  ) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const redirectedSeries = seriesByMetric.get(FIREWALL_REDIRECTED_METRIC) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const challengeSolvedSeries = seriesByMetric.get(
    FIREWALL_CHALLENGE_SOLVED_METRIC,
  ) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }
  const solveTimeSeries = seriesByMetric.get(
    FIREWALL_CHALLENGE_SOLVE_TIME_METRIC,
  ) ?? {
    chartPoints: [],
    previousChartPoints: [],
  }

  const passed = changeFor(
    requestsSeries.chartPoints,
    requestsSeries.previousChartPoints,
  )
  const denied = changeFor(
    deniedSeries.chartPoints,
    deniedSeries.previousChartPoints,
  )
  const challenged = changeFor(
    challengedSeries.chartPoints,
    challengedSeries.previousChartPoints,
  )
  const rateLimited = changeFor(
    rateLimitedSeries.chartPoints,
    rateLimitedSeries.previousChartPoints,
  )
  const redirected = changeFor(
    redirectedSeries.chartPoints,
    redirectedSeries.previousChartPoints,
  )
  const challengeSolved = changeFor(
    challengeSolvedSeries.chartPoints,
    challengeSolvedSeries.previousChartPoints,
  )

  // Solve time is reported as summed ms; average it over challenges solved.
  const solveTimeTotal = sumUsageChartPoints(solveTimeSeries.chartPoints)
  const previousSolveTimeTotal = sumUsageChartPoints(
    solveTimeSeries.previousChartPoints,
  )
  const previousChallengeSolved = sumUsageChartPoints(
    challengeSolvedSeries.previousChartPoints,
  )
  const avgSolveTimeMs =
    challengeSolved.total > 0 ? solveTimeTotal / challengeSolved.total : 0
  const previousAvgSolveTimeMs =
    previousChallengeSolved > 0
      ? previousSolveTimeTotal / previousChallengeSolved
      : 0

  // Top total = sum of every series on the chart (no extra fetch).
  const totalRequests =
    passed.total +
    denied.total +
    challenged.total +
    rateLimited.total +
    redirected.total
  const previousTotalRequests =
    sumUsageChartPoints(requestsSeries.previousChartPoints) +
    sumUsageChartPoints(deniedSeries.previousChartPoints) +
    sumUsageChartPoints(challengedSeries.previousChartPoints) +
    sumUsageChartPoints(rateLimitedSeries.previousChartPoints) +
    sumUsageChartPoints(redirectedSeries.previousChartPoints)

  const previousBlocked =
    sumUsageChartPoints(deniedSeries.previousChartPoints) +
    sumUsageChartPoints(rateLimitedSeries.previousChartPoints)
  const currentBlocked = denied.total + rateLimited.total

  const previousBlockRate =
    previousTotalRequests > 0
      ? (previousBlocked / previousTotalRequests) * 100
      : 0
  const currentBlockRate =
    totalRequests > 0 ? (currentBlocked / totalRequests) * 100 : 0

  return {
    totalRequests,
    totalPassed: passed.total,
    totalDenied: denied.total,
    totalChallenged: challenged.total,
    totalRateLimited: rateLimited.total,
    totalRedirected: redirected.total,
    totalChallengeSolved: challengeSolved.total,
    avgSolveTimeMs,
    requestsChange: computeChangePercent(totalRequests, previousTotalRequests),
    passedChange: passed.change,
    deniedChange: denied.change,
    challengedChange: challenged.change,
    rateLimitedChange: rateLimited.change,
    redirectedChange: redirected.change,
    challengeSolvedChange: challengeSolved.change,
    avgSolveTimeChange: computeChangePercent(
      avgSolveTimeMs,
      previousAvgSolveTimeMs,
    ),
    blockRateChange: computeChangePercent(currentBlockRate, previousBlockRate),
    chartPoints: mergeFirewallTrafficPoints(
      requestsSeries.chartPoints,
      deniedSeries.chartPoints,
      challengedSeries.chartPoints,
      rateLimitedSeries.chartPoints,
      redirectedSeries.chartPoints,
    ),
  }
}

function emptyFirewallTrafficOverview(): ProjectFirewallTrafficOverview {
  return {
    totalRequests: 0,
    totalPassed: 0,
    totalDenied: 0,
    totalChallenged: 0,
    totalRateLimited: 0,
    totalRedirected: 0,
    totalChallengeSolved: 0,
    avgSolveTimeMs: 0,
    requestsChange: 0,
    passedChange: 0,
    deniedChange: 0,
    challengedChange: 0,
    rateLimitedChange: 0,
    redirectedChange: 0,
    challengeSolvedChange: 0,
    avgSolveTimeChange: 0,
    blockRateChange: 0,
    chartPoints: [],
  }
}
