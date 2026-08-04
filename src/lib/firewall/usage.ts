import { Query, WafRuleAction } from '@appwrite.io/console'
import { subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import type { FirewallCreatableAction } from '@/lib/firewall/actions'
import { getFirewallActionMetric } from '@/lib/firewall/action-metrics'
import { FIREWALL_CHALLENGE_SOLVE_TIME_METRIC } from '@/lib/usage/firewall-events'
import { fetchUsageMetricsChartSeriesByMetric } from '@/lib/usage/usage-events-common'
import {
  FIREWALL_CONDITION_OPERATORS,
  isConditionDraftComplete,
  isNoValueOperator,
  isOperatorAllowedForAttribute,
  type FirewallConditionAttribute,
  type FirewallConditionDraft,
  type FirewallConditionOperator,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import {
  fetchProjectRequestsChartOverview,
  sumUsageChartPoints,
  type RequestsChartPoint,
} from '@/lib/usage/requests-events'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import type {
  FirewallActionActivityPoint,
  FirewallImpactPoint,
} from '@/lib/firewall/types'
import type { CompactFilterKey, FilterMap } from '@/lib/table-filters'

/** Firewall resourceType → usage.listEvents resourceType. `api` has no usage resourceType. */
const USAGE_RESOURCE_TYPE: Record<FirewallResourceType, string | null> = {
  api: null,
  functions: 'function',
  sites: 'site',
}

/**
 * Map a draft condition attribute to a usage.listEvents filter attribute.
 * `userAgent` is approximated with `clientName` (usage has no userAgent field).
 * Attributes usage cannot filter on (continent / state / headers / query)
 * return null and are skipped in affected-traffic estimation.
 */
function toUsageAttribute(attribute: string): string | null {
  switch (attribute) {
    case 'ip':
    case 'path':
    case 'method':
    case 'country':
    case 'city':
      return attribute
    case 'host':
      return 'hostname'
    case 'os':
      return 'osName'
    case 'browser':
    case 'userAgent':
      return 'clientName'
    default:
      return null
  }
}

/**
 * Usage filter attributes that exist 1:1 as firewall condition attributes.
 * `userAgent` is a firewall-only attribute (usage uses `clientName` instead,
 * which reverse-maps to `browser` here), so it is intentionally excluded.
 */
function toFirewallConditionAttribute(
  attribute: string,
): FirewallConditionAttribute | null {
  switch (attribute) {
    case 'ip':
    case 'path':
    case 'method':
    case 'country':
    case 'city':
      return attribute
    case 'hostname':
      return 'host'
    case 'osName':
      return 'os'
    case 'clientName':
      return 'browser'
    default:
      return null
  }
}

function isFirewallConditionOperator(
  operator: string,
): operator is FirewallConditionOperator {
  return FIREWALL_CONDITION_OPERATORS.some((op) => op.value === operator)
}

function compactFilterValue(key: CompactFilterKey): string {
  if (key.v == null) return ''
  if (Array.isArray(key.v)) return String(key.v[0] ?? '')
  return String(key.v)
}

/**
 * True when every applied usage filter can become a firewall condition
 * (same attribute + an operator allowed for that attribute on firewall rules).
 * Requires at least one filter.
 */
export function canApplyUsageFiltersAsFirewallRule(
  filterMap: FilterMap,
): boolean {
  if (filterMap.size === 0) return false

  for (const key of filterMap.keys()) {
    const attribute = toFirewallConditionAttribute(String(key.c))
    if (!attribute) return false
    if (!isFirewallConditionOperator(key.o)) return false
    if (!isOperatorAllowedForAttribute(attribute, key.o)) return false
    if (!isNoValueOperator(key.o) && compactFilterValue(key).trim().length === 0) {
      return false
    }
  }

  return true
}

/**
 * Convert usage FiltersPopover state into firewall condition drafts.
 * Returns null when any filter cannot be represented as a firewall condition.
 */
export function draftsFromUsageFilterMap(
  filterMap: FilterMap,
): FirewallConditionDraft[] | null {
  if (!canApplyUsageFiltersAsFirewallRule(filterMap)) return null

  return Array.from(filterMap.keys()).map((key) => {
    const attribute = toFirewallConditionAttribute(String(key.c))!
    const operator = key.o as FirewallConditionOperator
    const rawValue = compactFilterValue(key).trim()
    const value =
      attribute === 'country' ? rawValue.toUpperCase() : rawValue

    return {
      id: `cond_${Math.random().toString(36).slice(2, 10)}`,
      attribute,
      operator,
      value,
    }
  })
}

/** Operators with no usage.listEvents equivalent (skipped in estimation). */
const UNESTIMABLE_OPERATORS = new Set<FirewallConditionOperator>([
  'notContains',
])

/**
 * True when a condition cannot be represented as a usage filter (attribute or
 * operator has no usage equivalent) and is skipped by
 * `buildFirewallConditionUsageQueries` - making the estimate an upper bound.
 */
export function isUnestimableFirewallCondition(
  draft: FirewallConditionDraft,
): boolean {
  return (
    toUsageAttribute(draft.attribute) == null ||
    UNESTIMABLE_OPERATORS.has(draft.operator)
  )
}

/**
 * Number of complete conditions the affected-traffic estimate cannot include.
 * Callers should tell the user the estimate overcounts when this is > 0.
 */
export function countUnestimableFirewallConditions(
  conditions: FirewallConditionDraft[],
): number {
  return conditions.filter(
    (draft) =>
      isConditionDraftComplete(draft) && isUnestimableFirewallCondition(draft),
  ).length
}

/** usage.listEvents allows up to 10 queries; leave room for resource filters. */
export const FIREWALL_USAGE_CONDITION_QUERY_LIMIT = 8

/**
 * True when the rule has more estimable conditions than the usage query limit,
 * in which case `buildFirewallConditionUsageQueries` would drop the excess and
 * the estimate would describe a less restrictive rule.
 */
export function exceedsFirewallUsageConditionLimit(
  conditions: FirewallConditionDraft[],
): boolean {
  const estimable = conditions.filter(
    (draft) =>
      isConditionDraftComplete(draft) && !isUnestimableFirewallCondition(draft),
  ).length
  return estimable > FIREWALL_USAGE_CONDITION_QUERY_LIMIT
}

/**
 * Build usage.listEvents queries from firewall conditions.
 * Only attributes supported by the usage API are included (max 10 total with resource filters).
 * Empty check uses length so values like `/` are kept.
 */
export function buildFirewallConditionUsageQueries(
  conditions: FirewallConditionDraft[],
): string[] {
  const queries: string[] = []

  for (const draft of conditions) {
    const attribute = toUsageAttribute(draft.attribute)
    if (!attribute) continue
    if (UNESTIMABLE_OPERATORS.has(draft.operator)) continue

    if (isNoValueOperator(draft.operator)) {
      queries.push(
        draft.operator === 'isNotNull'
          ? Query.isNotNull(attribute)
          : Query.isNull(attribute),
      )
    } else {
      const value = draft.value.trim()
      if (value.length === 0) continue

      switch (draft.operator) {
        case 'notEqual':
          queries.push(Query.notEqual(attribute, value))
          break
        case 'contains':
          queries.push(Query.contains(attribute, value))
          break
        case 'startsWith':
          queries.push(Query.startsWith(attribute, value))
          break
        case 'endsWith':
          queries.push(Query.endsWith(attribute, value))
          break
        case 'equal':
        default:
          queries.push(Query.equal(attribute, value))
          break
      }
    }

    // Callers gate on exceedsFirewallUsageConditionLimit; this break is a backstop.
    if (queries.length >= FIREWALL_USAGE_CONDITION_QUERY_LIMIT) break
  }

  return queries
}

/** Resource scope filters for usage (resourceType / resourceId). */
export function buildFirewallResourceUsageQueries(
  resourceType: FirewallResourceType,
  resourceId?: string,
): string[] {
  const queries: string[] = []
  const usageResourceType = USAGE_RESOURCE_TYPE[resourceType]
  if (usageResourceType) {
    queries.push(Query.equal('resourceType', usageResourceType))
  }
  const id = resourceId?.trim()
  if (id && resourceType !== 'api') {
    queries.push(Query.equal('resourceId', id))
  }
  return queries
}

export function buildFirewallUsageQueries(options: {
  conditions: FirewallConditionDraft[]
  resourceType: FirewallResourceType
  resourceId?: string
  /** When false, only resource scope filters (total traffic baseline). */
  includeConditions?: boolean
}): string[] {
  const resourceQueries = buildFirewallResourceUsageQueries(
    options.resourceType,
    options.resourceId,
  )
  if (options.includeConditions === false) {
    return resourceQueries
  }
  return [
    ...resourceQueries,
    ...buildFirewallConditionUsageQueries(options.conditions),
  ]
}

/** Snapshot of filled conditions used in React Query keys / queryFn. */
export type FirewallUsageConditionSnapshot = {
  attribute: string
  operator: string
  value: string
}

/** Stable snapshots for React Query (value `/` must be preserved). */
export function buildFirewallUsageConditionSnapshots(
  conditions: FirewallConditionDraft[],
): FirewallUsageConditionSnapshot[] {
  const snapshots: FirewallUsageConditionSnapshot[] = []
  for (const draft of conditions) {
    const value = draft.value.trim()
    // No-value operators (is empty / is not empty) contribute without a value.
    if (value.length === 0 && !isNoValueOperator(draft.operator)) continue
    snapshots.push({
      attribute: draft.attribute,
      operator: draft.operator,
      value,
    })
  }
  return snapshots
}

/** Stable key for React Query when conditions change (JSON keeps `/` intact). */
export function firewallUsageConditionsKey(
  conditions: FirewallConditionDraft[],
): string {
  return JSON.stringify(buildFirewallUsageConditionSnapshots(conditions))
}

export function draftsFromUsageConditionSnapshots(
  snapshots: FirewallUsageConditionSnapshot[],
): FirewallConditionDraft[] {
  return snapshots.map((snapshot, index) => ({
    id: `impact_${index}`,
    attribute: snapshot.attribute as FirewallConditionDraft['attribute'],
    operator: snapshot.operator as FirewallConditionDraft['operator'],
    value: snapshot.value,
  }))
}

/**
 * Project-wide WAF activity for a rule's action (denied / rate limited /
 * redirected / challenge solves). These metrics are project-level counters with
 * no per-rule dimension, so the values are not scoped to the individual rule.
 */
export type FirewallRuleActionActivity = {
  action: FirewallCreatableAction
  /** Total count of the action metric over the period. */
  total: number
  /** Count per interval, for the activity graph. */
  series: FirewallActionActivityPoint[]
  /** Overall average solve time in ms — challenge action only. */
  avgSolveTimeMs?: number
}

/** Build activity points from a count metric, optionally with solve time. */
function buildActionActivitySeries(
  countPoints: RequestsChartPoint[],
  solveTimePoints?: RequestsChartPoint[],
): FirewallActionActivityPoint[] {
  const solveTimeByTime = new Map(
    (solveTimePoints ?? []).map((point) => [point.day.getTime(), point.total]),
  )

  return countPoints.map((point) => {
    const value = point.total
    const base: FirewallActionActivityPoint = {
      date: point.date,
      day: point.day,
      fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
      value,
    }
    if (solveTimePoints) {
      const solveTimeTotal = solveTimeByTime.get(point.day.getTime()) ?? 0
      base.avgSolveTimeMs = value > 0 ? solveTimeTotal / value : 0
    }
    return base
  })
}

export type FirewallRuleImpactData = {
  series: FirewallImpactPoint[]
  matched: number
  total: number
  rate: number
  dateRange: { from: Date; to: Date }
  /** Project-wide activity for the rule's action (denied / rate limited / …). */
  activity?: FirewallRuleActionActivity
}

/** Compact ms/s label for challenge solve time. */
export function formatFirewallSolveTime(ms: number): string {
  if (ms <= 0) return '0ms'
  if (ms >= 1000) return `${(ms / 1000).toFixed(1)}s`
  return `${Math.round(ms)}ms`
}

function mergeImpactSeries(
  totalPoints: RequestsChartPoint[],
  matchedPoints: RequestsChartPoint[],
): FirewallImpactPoint[] {
  const matchedByTime = new Map(
    matchedPoints.map((point) => [point.day.getTime(), point.total]),
  )

  return totalPoints.map((point) => {
    const matched = matchedByTime.get(point.day.getTime()) ?? 0
    return {
      date: point.date,
      day: point.day,
      fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
      total: point.total,
      matched: Math.min(point.total, matched),
    }
  })
}

/**
 * Fetch estimated impact for a draft rule using usage.listEvents (`network.requests`).
 * Total = traffic in the resource scope; matched = same scope + condition filters.
 * Values like `/` are valid (truthy) and must produce condition queries.
 */
export async function fetchFirewallRuleImpact(
  projectId: string,
  options: {
    conditions: FirewallConditionDraft[]
    resourceType: FirewallResourceType
    resourceId?: string
    dateRange?: DateRange
    chartInterval?: UsageChartInterval
    logRetentionHours?: number
    action?: FirewallCreatableAction
  },
): Promise<FirewallRuleImpactData> {
  const to = options.dateRange?.to ?? new Date()
  const from = options.dateRange?.from ?? subHours(to, 24)
  const dateRange: DateRange = { from, to }
  const chartInterval =
    options.chartInterval ?? DEFAULT_USAGE_CHART_INTERVAL
  const logRetentionHours = options.logRetentionHours

  const conditionQueries = buildFirewallConditionUsageQueries(
    options.conditions,
  )
  const hasConditionFilters = conditionQueries.length > 0

  const totalQueries = buildFirewallUsageQueries({
    conditions: options.conditions,
    resourceType: options.resourceType,
    resourceId: options.resourceId,
    includeConditions: false,
  })
  const matchedQueries = buildFirewallUsageQueries({
    conditions: options.conditions,
    resourceType: options.resourceType,
    resourceId: options.resourceId,
    includeConditions: true,
  })

  // Only Site challenge rules get an activity section: solves / avg solve time
  // come from the browser HTML-navigation challenge, so they only apply to sites
  // (not functions/api). The block actions (denied / rateLimited / redirected)
  // are already represented by matched traffic, so we don't fetch or show them.
  // Challenge scopes by resource ONLY — clearance is site-wide, so solves aren't
  // tied to a path/condition.
  const isSiteChallenge =
    options.action === WafRuleAction.Challenge &&
    options.resourceType === 'sites'
  const activityConfig = isSiteChallenge
    ? getFirewallActionMetric(options.action)
    : undefined
  const activityMetrics = activityConfig
    ? [activityConfig.metric, FIREWALL_CHALLENGE_SOLVE_TIME_METRIC]
    : []

  const [totalOverview, matchedOverview, activitySeries] = await Promise.all([
    fetchProjectRequestsChartOverview(
      projectId,
      dateRange,
      chartInterval,
      totalQueries.length > 0 ? totalQueries : undefined,
      logRetentionHours,
    ),
    hasConditionFilters
      ? fetchProjectRequestsChartOverview(
          projectId,
          dateRange,
          chartInterval,
          matchedQueries,
          logRetentionHours,
        )
      : Promise.resolve(null),
    activityConfig
      ? fetchUsageMetricsChartSeriesByMetric(
          projectId,
          activityMetrics,
          dateRange,
          chartInterval,
          // Resource scope only (challenge clearance is site-wide).
          totalQueries.length > 0 ? totalQueries : undefined,
          logRetentionHours,
        )
      : Promise.resolve(null),
  ])

  let activity: FirewallRuleActionActivity | undefined
  if (activitySeries && activityConfig && options.action) {
    const countPoints =
      activitySeries.get(activityConfig.metric)?.chartPoints ?? []
    const total = sumUsageChartPoints(countPoints)
    const solveTimePoints =
      activitySeries.get(FIREWALL_CHALLENGE_SOLVE_TIME_METRIC)?.chartPoints ?? []
    const solveTimeTotal = sumUsageChartPoints(solveTimePoints)
    activity = {
      action: options.action,
      total,
      avgSolveTimeMs: total > 0 ? solveTimeTotal / total : 0,
      series: buildActionActivitySeries(countPoints, solveTimePoints),
    }
  }

  const matchedChart = matchedOverview ?? totalOverview
  const series = mergeImpactSeries(
    totalOverview.chartPoints,
    matchedChart.chartPoints,
  )
  const total = sumUsageChartPoints(totalOverview.chartPoints)
  const matched = hasConditionFilters
    ? Math.min(total, sumUsageChartPoints(matchedChart.chartPoints))
    : total

  return {
    series: hasConditionFilters
      ? series
      : totalOverview.chartPoints.map((point) => ({
          date: point.date,
          day: point.day,
          fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
          total: point.total,
          matched: point.total,
        })),
    total,
    matched,
    rate: total > 0 ? matched / total : 0,
    dateRange: { from, to },
    activity,
  }
}
