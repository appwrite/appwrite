import { parseISO } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import {
  OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  resolveUsageListOrder,
} from '@/lib/usage/breakdown-limits'
import { areUsageBreakdownQueriesEnabled } from '@/lib/debug-overrides'
import {
  computeChangePercent,
  fillGaugeChartPointsGaps,
  getUsageChartFirstHalfPoints,
  resolveOverviewUsagePeriod,
  buildScreenshotModeChartPoints,
  type ProjectUsageChartOverview,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'
import { isUsageProjectResourceType } from '@/lib/usage/usage-resource-filters'
import { buildUsageResourceFilterQueries } from '@/lib/usage/usage-resource-queries'
import { isScreenshotModeActive } from '@/lib/screenshot-mode'

export type {
  UsageTopEndpoint,
  UsageChartInterval,
} from '@/lib/usage/usage-events-common'

export interface ProjectUsageGaugeOverview {
  changePercent: number
  latestValue: number
  topConsumers: UsageTopEndpoint[]
}

/**
 * How samples inside a bucket combine.
 *
 * `last` is the gauge default - the latest reading, which is the answer for a
 * snapshot like storage. `max` is for a sampled *level* series, where the
 * bucket's highest reading is what matters: realtime concurrency is sampled
 * every few minutes, so its peak is the max of those samples.
 */
export type UsageGaugeAggregate = 'last' | 'max'

/** Closed listGauges dimension contract used at the SDK boundary. */
export type UsageGaugeApiDimension =
  | 'resourceId'
  | 'teamId'
  | 'service'
  | 'resourceType'
  | 'ordinal'

interface ListUsageGaugeGroupsParams {
  metrics: readonly string[]
  interval?: UsageChartInterval
  startAt: string
  endAt: string
  dimensions?: UsageGaugeApiDimension[]
  queries?: string[]
  resourceId?: string
  resourceType?: string
  /** Cluster node: 0 = primary, 1+ = replicas. */
  ordinal?: number
  teamId?: string
  /**
   * Max rows from listGauges. Flat top-N breakdowns should pass the UI limit
   * with value-desc ordering; charts omit this and use USAGE_API_MAX_LIMIT.
   */
  limit?: number
  /**
   * How samples in a bucket combine. `last` (default) is the latest reading,
   * right for a snapshot such as storage. `max` is the highest, which a
   * sampled level series needs - see UsageGaugeAggregate.
   */
  aggregate?: UsageGaugeAggregate
}

async function listUsageGaugeGroupsByMetric(
  projectId: string,
  params: ListUsageGaugeGroupsParams,
): Promise<Map<string, Models.UsageDataPoint[]>> {
  if (params.metrics.length === 0) {
    return new Map()
  }

  const projectSdk = sdk.forProject(projectId)
  // Prefer Utopia queries for filters - top-level resourceId is not in the SDK.
  const queries = [
    ...(buildUsageResourceFilterQueries({
      queries: params.queries,
      resourceId: params.resourceId,
      resourceType: params.resourceType,
      ordinal: params.ordinal,
    }) ?? []),
  ]
  const teamId = params.teamId?.trim()
  if (teamId && !queries.some((query) => query.includes('"teamId"'))) {
    queries.push(Query.equal('teamId', teamId))
  }

  const { orderBy, orderDir, limit } = resolveUsageListOrder({
    interval: params.interval,
    hasDimensions: (params.dimensions?.length ?? 0) > 0,
    limit: params.limit,
  })
  const request: {
    metrics: string[]
    interval?: UsageChartInterval
    startAt: string
    endAt: string
    dimensions?: UsageGaugeApiDimension[]
    queries?: string[]
    orderBy?: 'time' | 'value'
    orderDir?: 'asc' | 'desc'
    limit?: number
    aggregate?: UsageGaugeAggregate
  } = {
    metrics: [...params.metrics],
    startAt: params.startAt,
    endAt: params.endAt,
    orderBy,
    orderDir,
    limit,
  }

  if (params.interval) {
    request.interval = params.interval
  }
  if (params.dimensions?.length) {
    request.dimensions = params.dimensions
  }
  if (queries.length > 0) {
    request.queries = queries
  }
  if (params.aggregate) {
    request.aggregate = params.aggregate
  }

  const response = await projectSdk.usage.listGauges(request)
  const result = new Map<string, Models.UsageDataPoint[]>()

  for (const metric of params.metrics) {
    result.set(
      metric,
      response.metrics?.find((entry) => entry.metric === metric)?.points ?? [],
    )
  }

  return result
}

async function listUsageGaugeGroups(
  projectId: string,
  params: Omit<ListUsageGaugeGroupsParams, 'metrics'> & { metric: string },
): Promise<Models.UsageDataPoint[]> {
  const groupsByMetric = await listUsageGaugeGroupsByMetric(projectId, {
    ...params,
    metrics: [params.metric],
  })

  return groupsByMetric.get(params.metric) ?? []
}

function mapGaugeBreakdownGroups(
  groups: Models.UsageDataPoint[],
  dimensions: readonly UsageGaugeApiDimension[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageTopEndpoint[] {
  const useResourceDimensions =
    dimensions.includes('resourceId') && dimensions.includes('resourceType')
  const dimension =
    dimensions[0] === 'resourceType' ? 'resourceType' : 'resourceId'
  const latestByKey = new Map<
    string,
    { value: number; timeMs: number; resourceType?: string }
  >()

  for (const group of groups) {
    const resourceId = group.resourceId?.trim()
    const resourceType = group.resourceType?.trim()
    const key = useResourceDimensions
      ? isUsageProjectResourceType(resourceType)
        ? 'project'
        : resourceId && resourceType
          ? `${resourceType}\0${resourceId}`
          : undefined
      : dimension === 'resourceType'
        ? resourceType
        : resourceId
    if (!key) continue

    const timeMs = parseISO(group.time).getTime()
    const existing = latestByKey.get(key)
    if (!existing || timeMs >= existing.timeMs) {
      latestByKey.set(key, {
        value: group.value,
        timeMs,
        resourceType: useResourceDimensions ? resourceType : undefined,
      })
    }
  }

  // Drop non-positive rows. Dimensioned gauge responses often include zero
  // placeholders (or last-bucket zeros) for resources with no usage in range;
  // showing "Storage / bucket · 0" while the chart is flat is misleading.
  return Array.from(latestByKey.entries())
    .filter(([, { value }]) => value > 0)
    .sort((a, b) => b[1].value - a[1].value)
    .slice(0, limit)
    .map(([key, { value, resourceType }]) => {
      if (useResourceDimensions && isUsageProjectResourceType(resourceType)) {
        return {
          id: 'project',
          method: '',
          statusCode: 0,
          path: 'project',
          count: value,
          resourceType: 'project',
        }
      }
      const resourceId = useResourceDimensions
        ? (key.split('\0')[1] ?? key)
        : key
      return {
        id: resourceId,
        method: '',
        statusCode: 0,
        path: resourceId,
        count: value,
        resourceType,
      }
    })
}

/** @deprecated Use mapGaugeBreakdownGroups */
function mapGaugeResourceBreakdownGroups(
  groups: Models.UsageDataPoint[],
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
): UsageTopEndpoint[] {
  return mapGaugeBreakdownGroups(groups, ['resourceId'], limit)
}

/** Latest gauge snapshot in a window (most recent by time, client-side). */
function getLatestUsageGaugeValueInRange(
  groups: Models.UsageDataPoint[],
  startAt: Date,
  endAt: Date,
): number {
  const startMs = startAt.getTime()
  const endMs = endAt.getTime()
  let latestValue = 0
  let latestTimeMs = -1

  for (const group of groups) {
    const timeMs = parseISO(group.time).getTime()
    if (timeMs < startMs || timeMs > endMs) {
      continue
    }

    if (timeMs >= latestTimeMs) {
      latestTimeMs = timeMs
      latestValue = group.value
    }
  }

  return latestValue
}

/** Latest gauge snapshot in a window (most recent by time, client-side). */
export async function fetchLatestUsageGaugeValue(
  projectId: string,
  metric: string,
  startAt: Date,
  endAt: Date,
): Promise<number> {
  const groups = await listUsageGaugeGroups(projectId, {
    metric,
    startAt: startAt.toISOString(),
    endAt: endAt.toISOString(),
  })

  return getLatestUsageGaugeValueInRange(groups, startAt, endAt)
}

/** Flat top-N gauge breakdown (no interval), sorted client-side. */
export async function fetchUsageGaugeBreakdown(
  projectId: string,
  metric: string,
  startAt: Date,
  endAt: Date,
  dimensions: readonly UsageGaugeApiDimension[],
  breakdownLimit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
): Promise<UsageTopEndpoint[]> {
  const groups = await listUsageGaugeGroups(projectId, {
    metric,
    dimensions: [...dimensions],
    startAt: startAt.toISOString(),
    endAt: endAt.toISOString(),
    queries,
    limit: breakdownLimit,
  })

  return mapGaugeBreakdownGroups(groups, dimensions, breakdownLimit)
}

async function listUsageGaugeGroupsForMetrics(
  projectId: string,
  metrics: readonly string[],
  params: Omit<ListUsageGaugeGroupsParams, 'metrics'>,
): Promise<Models.UsageDataPoint[]> {
  const groupsByMetric = await listUsageGaugeGroupsByMetric(projectId, {
    ...params,
    metrics,
  })

  return Array.from(groupsByMetric.values()).flat()
}

function mergeGaugeValuesByTime(
  groups: Models.UsageDataPoint[],
): Map<string, number> {
  const merged = new Map<string, number>()
  for (const group of groups) {
    merged.set(group.time, (merged.get(group.time) ?? 0) + group.value)
  }
  return merged
}

/** A chart series built from one or more `resourceType` values of a gauge. */
export interface UsageGaugeResourceTypeSeries {
  key: string
  resourceTypes: readonly string[]
}

function mergeGaugeValuesByTimePerSeries(
  groups: Models.UsageDataPoint[],
  series: readonly UsageGaugeResourceTypeSeries[],
): Map<string, Map<string, number>> {
  const seriesKeyByResourceType = new Map<string, string>()
  for (const entry of series) {
    for (const resourceType of entry.resourceTypes) {
      seriesKeyByResourceType.set(resourceType, entry.key)
    }
  }

  const merged = new Map<string, Map<string, number>>(
    series.map((entry) => [entry.key, new Map<string, number>()]),
  )

  for (const group of groups) {
    const seriesKey = seriesKeyByResourceType.get(
      group.resourceType?.trim() ?? '',
    )
    if (!seriesKey) continue

    const byTime = merged.get(seriesKey)!
    byTime.set(group.time, (byTime.get(group.time) ?? 0) + group.value)
  }

  return merged
}

/**
 * Current and previous gauge chart series, one per `resourceType` group.
 *
 * Per-resource gauges (the unified `storage` metric) hold one row per resource
 * instance, so a resource type's value in a bucket is the sum of every
 * resource's snapshot. Grouping on `resourceType` alone would let the server's
 * `argMax` collapse each bucket to a single resource, so `resourceId` stays in
 * `dimensions` and the sum happens here. Rows are capped at
 * `USAGE_API_MAX_LIMIT` (buckets x resources), so very large projects on a fine
 * interval can truncate the oldest end of the series.
 */
/**
 * A gauge is a level, not a tally. The collector writes a snapshot every
 * interval — including an explicit zero when the level really is zero — so a
 * window with no samples at all means the level is unknown, not that it was
 * zero. Returning an empty series lets the card say so instead of drawing a
 * flat zero line the backend never reported. Events are the opposite: a row
 * exists only when something happened, so an absent bucket there is a real
 * zero and keeps its gap filled.
 */
function buildGaugeChartPoints(
  samples: Map<string, number> | undefined,
  from: Date,
  to: Date,
  interval: UsageChartInterval,
): UsageChartPoint[] {
  if (!samples?.size) {
    return []
  }
  return fillGaugeChartPointsGaps(samples, from, to, interval)
}

export async function fetchProjectUsageGaugeChartSeriesByResourceType(
  projectId: string,
  dateRange: DateRange | undefined,
  metric: string,
  series: readonly UsageGaugeResourceTypeSeries[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): Promise<{
  chartPointsBySeries: Map<string, ProjectUsageChartOverview['chartPoints']>
  previousChartPointsBySeries: Map<
    string,
    ProjectUsageChartOverview['chartPoints']
  >
}> {
  const emptySeries = () =>
    new Map(series.map((entry) => [entry.key, [] as UsageChartPoint[]]))

  if (!projectId || series.length === 0) {
    return {
      chartPointsBySeries: emptySeries(),
      previousChartPointsBySeries: emptySeries(),
    }
  }

  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
    comparisonMode,
  } = resolveOverviewUsagePeriod(dateRange, interval, logRetentionHours)

  if (isScreenshotModeActive()) {
    const chartPointsBySeries = new Map<string, UsageChartPoint[]>()
    const previousChartPointsBySeries = new Map<string, UsageChartPoint[]>()
    for (const entry of series) {
      const chartPoints = buildScreenshotModeChartPoints(
        from,
        to,
        resolvedInterval,
        `${metric}:${entry.key}`,
        { gauge: true },
      )
      chartPointsBySeries.set(entry.key, chartPoints)
      previousChartPointsBySeries.set(
        entry.key,
        comparisonMode === 'first_half'
          ? getUsageChartFirstHalfPoints(chartPoints)
          : buildScreenshotModeChartPoints(
              previousFrom,
              previousTo,
              resolvedInterval,
              `${metric}:${entry.key}`,
              { quieter: true, gauge: true },
            ),
      )
    }
    return { chartPointsBySeries, previousChartPointsBySeries }
  }

  const resourceTypes = series.flatMap((entry) => [...entry.resourceTypes])
  const scopedQueries = [
    ...(queries ?? []),
    Query.equal('resourceType', resourceTypes),
  ]
  const dimensions: UsageGaugeApiDimension[] = ['resourceType', 'resourceId']

  const currentGroups = await listUsageGaugeGroupsForMetrics(
    projectId,
    [metric],
    {
      interval: resolvedInterval,
      startAt: from.toISOString(),
      endAt: to.toISOString(),
      dimensions,
      queries: scopedQueries,
    },
  )

  // First-half comparison reuses the current series; a second fetch would
  // request the same buckets we already have.
  const previousGroups =
    comparisonMode === 'prior_window'
      ? await listUsageGaugeGroupsForMetrics(projectId, [metric], {
          interval: resolvedInterval,
          startAt: previousFrom.toISOString(),
          endAt: previousTo.toISOString(),
          dimensions,
          queries: scopedQueries,
        })
      : []

  const currentBySeries = mergeGaugeValuesByTimePerSeries(currentGroups, series)
  const previousBySeries = mergeGaugeValuesByTimePerSeries(
    previousGroups,
    series,
  )

  const chartPointsBySeries = new Map<string, UsageChartPoint[]>()
  const previousChartPointsBySeries = new Map<string, UsageChartPoint[]>()

  for (const entry of series) {
    const chartPoints = buildGaugeChartPoints(
      currentBySeries.get(entry.key),
      from,
      to,
      resolvedInterval,
    )
    chartPointsBySeries.set(entry.key, chartPoints)
    previousChartPointsBySeries.set(
      entry.key,
      comparisonMode === 'first_half'
        ? getUsageChartFirstHalfPoints(chartPoints)
        : buildGaugeChartPoints(
            previousBySeries.get(entry.key),
            previousFrom,
            previousTo,
            resolvedInterval,
          ),
    )
  }

  return { chartPointsBySeries, previousChartPointsBySeries }
}

/** Current and previous gauge chart series (merged per bucket). */
export async function fetchProjectUsageGaugeChartSeries(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  resourceId?: string,
  resourceType?: string,
  ordinal?: number,
  aggregate?: UsageGaugeAggregate,
): Promise<{
  chartPoints: ProjectUsageChartOverview['chartPoints']
  previousChartPoints: ProjectUsageChartOverview['chartPoints']
}> {
  if (!projectId || metrics.length === 0) {
    return { chartPoints: [], previousChartPoints: [] }
  }

  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
    comparisonMode,
  } = resolveOverviewUsagePeriod(dateRange, interval, logRetentionHours)

  if (isScreenshotModeActive()) {
    const metricKey = metrics.join('|')
    const chartPoints = buildScreenshotModeChartPoints(
      from,
      to,
      resolvedInterval,
      metricKey,
      { gauge: true },
    )
    const previousChartPoints =
      comparisonMode === 'first_half'
        ? getUsageChartFirstHalfPoints(chartPoints)
        : buildScreenshotModeChartPoints(
            previousFrom,
            previousTo,
            resolvedInterval,
            metricKey,
            { quieter: true, gauge: true },
          )
    return { chartPoints, previousChartPoints }
  }

  const currentGroups = await listUsageGaugeGroupsForMetrics(
    projectId,
    metrics,
    {
      interval: resolvedInterval,
      startAt: from.toISOString(),
      endAt: to.toISOString(),
      queries,
      resourceId,
      resourceType,
      ordinal,
      aggregate,
    },
  )

  // First-half comparison reuses the current series; a second fetch would
  // request the same buckets we already have.
  const previousGroups =
    comparisonMode === 'prior_window'
      ? await listUsageGaugeGroupsForMetrics(projectId, metrics, {
          interval: resolvedInterval,
          startAt: previousFrom.toISOString(),
          endAt: previousTo.toISOString(),
          queries,
          resourceId,
          resourceType,
          ordinal,
          aggregate,
        })
      : []

  const chartPoints = buildGaugeChartPoints(
    mergeGaugeValuesByTime(currentGroups),
    from,
    to,
    resolvedInterval,
  )
  const previousChartPoints =
    comparisonMode === 'first_half'
      ? getUsageChartFirstHalfPoints(chartPoints)
      : buildGaugeChartPoints(
          mergeGaugeValuesByTime(previousGroups),
          previousFrom,
          previousTo,
          resolvedInterval,
        )

  return {
    chartPoints,
    previousChartPoints,
  }
}

/** Time-series chart for one or more gauge metrics (merged per bucket). */
export async function fetchProjectUsageGaugesChartOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  queries?: string[],
  logRetentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  resourceId?: string,
  resourceType?: string,
  ordinal?: number,
): Promise<ProjectUsageChartOverview> {
  if (!projectId || metrics.length === 0) {
    return { changePercent: 0, chartPoints: [] }
  }

  const { chartPoints, previousChartPoints } =
    await fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      metrics,
      interval,
      queries,
      logRetentionHours,
      resourceId,
      resourceType,
      ordinal,
    )

  const currentLatest =
    chartPoints.length > 0 ? chartPoints[chartPoints.length - 1].total : 0
  const previousLatest =
    previousChartPoints.length > 0
      ? previousChartPoints[previousChartPoints.length - 1].total
      : 0

  return {
    changePercent: computeChangePercent(currentLatest, previousLatest),
    chartPoints,
  }
}

/** Latest file storage value + top buckets for the overview storage tab. */
export async function fetchProjectUsageGaugeSnapshotOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  metric: string,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  breakdown?: {
    dimensions: readonly UsageGaugeApiDimension[]
    limit?: number
  },
  options?: {
    includeBreakdown?: boolean
    queries?: string[]
    logRetentionHours?: number
  },
): Promise<ProjectUsageGaugeOverview> {
  if (!projectId) {
    return { changePercent: 0, latestValue: 0, topConsumers: [] }
  }

  const logRetentionHours =
    options?.logRetentionHours ?? DEFAULT_USAGE_LOG_RETENTION_HOURS

  const { from, to, previousFrom, previousTo } = resolveOverviewUsagePeriod(
    dateRange,
    interval,
    logRetentionHours,
  )

  const includeBreakdown =
    options?.includeBreakdown !== false &&
    breakdown != null &&
    areUsageBreakdownQueriesEnabled()
  const queries = options?.queries

  const [snapshotGroups, topConsumers] = await Promise.all([
    listUsageGaugeGroups(projectId, {
      metric,
      startAt: previousFrom.toISOString(),
      endAt: to.toISOString(),
      queries,
    }),
    includeBreakdown
      ? fetchUsageGaugeBreakdown(
          projectId,
          metric,
          from,
          to,
          breakdown!.dimensions,
          breakdown!.limit ?? OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
          queries,
        )
      : Promise.resolve([]),
  ])

  const latestValue = getLatestUsageGaugeValueInRange(snapshotGroups, from, to)
  const previousValue = getLatestUsageGaugeValueInRange(
    snapshotGroups,
    previousFrom,
    previousTo,
  )

  return {
    latestValue,
    changePercent: computeChangePercent(latestValue, previousValue),
    topConsumers,
  }
}
