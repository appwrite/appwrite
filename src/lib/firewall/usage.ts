import { Query } from '@appwrite.io/console'
import { subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  isNoValueOperator,
  type FirewallConditionDraft,
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
import type { FirewallImpactPoint } from '@/lib/firewall/types'

/** Firewall resourceType → usage.listEvents resourceType. `api` has no usage resourceType. */
const USAGE_RESOURCE_TYPE: Record<FirewallResourceType, string | null> = {
  api: null,
  functions: 'function',
  sites: 'site',
}

/**
 * Map a draft condition attribute to a usage.listEvents filter attribute.
 * `userAgent` is approximated with `clientName` (usage has no userAgent field).
 */
function toUsageAttribute(attribute: string): string | null {
  switch (attribute) {
    case 'ip':
    case 'path':
    case 'method':
    case 'country':
      return attribute
    case 'userAgent':
      return 'clientName'
    default:
      return null
  }
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

    // usage.listEvents allows up to 10 queries; leave room for resource filters
    if (queries.length >= 8) break
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

export type FirewallRuleImpactData = {
  series: FirewallImpactPoint[]
  matched: number
  total: number
  rate: number
  dateRange: { from: Date; to: Date }
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
  },
): Promise<FirewallRuleImpactData> {
  const to = options.dateRange?.to ?? new Date()
  const from = options.dateRange?.from ?? subHours(to, 24)
  const dateRange: DateRange = { from, to }
  const chartInterval =
    options.chartInterval ?? DEFAULT_USAGE_CHART_INTERVAL

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

  const [totalOverview, matchedOverview] = await Promise.all([
    fetchProjectRequestsChartOverview(
      projectId,
      dateRange,
      chartInterval,
      totalQueries.length > 0 ? totalQueries : undefined,
    ),
    hasConditionFilters
      ? fetchProjectRequestsChartOverview(
          projectId,
          dateRange,
          chartInterval,
          matchedQueries,
        )
      : Promise.resolve(null),
  ])

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
  }
}
