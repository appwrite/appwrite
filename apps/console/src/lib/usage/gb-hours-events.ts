import type { DateRange } from 'react-day-picker'
import {
  fetchProjectUsageMetricsOverview,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { COMPUTE_BREAKDOWN_RESOURCE_LIMIT } from '@/lib/usage/breakdown-limits'
import {
  formatGbHoursAxisValue,
  formatGbHoursTotal,
  formatGbHoursValue,
  mbSecondsToGbHours,
} from '@/lib/usage/format-metric'

/** Combined MB-seconds metrics that roll up to GB-hours (overview). */
export const GB_HOURS_MB_SECONDS_METRICS = [
  'executions.mbSeconds',
  'builds.mbSeconds',
] as const

/** Function execution and build MB-seconds rolled up to GB-hours. */
export const FUNCTION_GB_HOURS_MB_SECONDS_METRICS = [
  'functions.executions.mbSeconds',
  'functions.builds.mbSeconds',
] as const

/** Site execution and build MB-seconds rolled up to GB-hours. */
export const SITE_GB_HOURS_MB_SECONDS_METRICS = [
  'sites.executions.mbSeconds',
  'sites.builds.mbSeconds',
] as const

const GB_HOURS_BREAKDOWN_DIMENSIONS = ['resourceId', 'resourceType'] as const

export type GbHoursChartPoint = UsageChartPoint
export type GbHoursTopConsumer = UsageTopEndpoint

export interface ProjectGbHoursOverview {
  changePercent: number
  chartPoints: GbHoursChartPoint[]
  topConsumers: GbHoursTopConsumer[]
}

export {
  formatGbHoursTotal,
  formatGbHoursValue,
  formatGbHoursAxisValue,
  mbSecondsToGbHours,
}
export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

function convertChartPointsToGbHours(
  points: UsageChartPoint[],
): GbHoursChartPoint[] {
  return points.map((point) => ({
    ...point,
    total: mbSecondsToGbHours(point.total),
  }))
}

function convertTopConsumersToGbHours(
  items: UsageTopEndpoint[],
): GbHoursTopConsumer[] {
  return items.map((item) => ({
    ...item,
    count: mbSecondsToGbHours(item.count),
  }))
}

async function fetchGbHoursOverviewForMetrics(
  projectId: string,
  dateRange: DateRange | undefined,
  metrics: readonly string[],
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectGbHoursOverview> {
  const overview = await fetchProjectUsageMetricsOverview(
    projectId,
    dateRange,
    metrics,
    interval,
    GB_HOURS_BREAKDOWN_DIMENSIONS,
    COMPUTE_BREAKDOWN_RESOURCE_LIMIT,
    options,
  )

  return {
    chartPoints: convertChartPointsToGbHours(overview.chartPoints),
    topConsumers: convertTopConsumersToGbHours(overview.topEndpoints),
    changePercent: overview.changePercent,
  }
}

/** Chart series + server-side top resource breakdown (functions and sites). */
export async function fetchProjectGbHoursOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectGbHoursOverview> {
  return fetchGbHoursOverviewForMetrics(
    projectId,
    dateRange,
    GB_HOURS_MB_SECONDS_METRICS,
    interval,
    options,
  )
}

/** Chart series + server-side top function compute breakdown. */
export async function fetchProjectFunctionGbHoursOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectGbHoursOverview> {
  return fetchGbHoursOverviewForMetrics(
    projectId,
    dateRange,
    FUNCTION_GB_HOURS_MB_SECONDS_METRICS,
    interval,
    options,
  )
}

/** Chart series + server-side top site compute breakdown. */
export async function fetchProjectSiteGbHoursOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<ProjectGbHoursOverview> {
  return fetchGbHoursOverviewForMetrics(
    projectId,
    dateRange,
    SITE_GB_HOURS_MB_SECONDS_METRICS,
    interval,
    options,
  )
}
