import type { DateRange } from 'react-day-picker'
import { UsageInterval, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { formatCompactCount } from '@/lib/usage/format-metric'
import {
  computeChangePercent,
  fillChartPointsGaps,
  getUsageChartFirstHalfPoints,
  mergeChartPointsSeries,
  resolveOverviewUsagePeriod,
  sumUsageChartPoints,
  sumUsageChartPointsForComparison,
  type UsageChartInterval,
  type UsageChartPoint,
  type UsagePeriodComparisonMode,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

/** User message created. */
export const AGENT_MESSAGES_EVENT_METRIC = 'agent.messages' as const

/** Conversation created. */
export const AGENT_CONVERSATIONS_EVENT_METRIC = 'agent.conversations' as const

/** Tool call finished. */
export const AGENT_TOOL_CALLS_EVENT_METRIC = 'agent.toolCalls' as const

/** Automation run started. */
export const AGENT_AUTOMATIONS_EVENT_METRIC = 'agent.automations' as const

/** Input tokens consumed. */
export const AGENT_TOKENS_INPUT_EVENT_METRIC = 'agent.tokens.input' as const

/** Output tokens consumed. */
export const AGENT_TOKENS_OUTPUT_EVENT_METRIC = 'agent.tokens.output' as const

export type AgentUsageEventMetric =
  | typeof AGENT_MESSAGES_EVENT_METRIC
  | typeof AGENT_CONVERSATIONS_EVENT_METRIC
  | typeof AGENT_TOOL_CALLS_EVENT_METRIC
  | typeof AGENT_AUTOMATIONS_EVENT_METRIC
  | typeof AGENT_TOKENS_INPUT_EVENT_METRIC
  | typeof AGENT_TOKENS_OUTPUT_EVENT_METRIC

export type AgentUsageMetricId =
  | 'tokens-input'
  | 'tokens-output'
  | 'messages'
  | 'conversations'
  | 'tool-calls'
  | 'automations'

export type AgentUsageChartPoint = UsageChartPoint

export interface AgentUsageChartOverview {
  changePercent: number
  chartPoints: AgentUsageChartPoint[]
}

export type AgentUsageSeriesDef = {
  dataKey: string
  label: string
  colorVar: string
  gradientId: string
}

export type AgentUsageMultiSeriesPoint = UsageChartPoint &
  Record<string, number | string | Date>

export interface AgentUsageMultiSeriesOverview {
  changePercent: number
  chartPoints: AgentUsageChartPoint[]
  multiSeriesPoints: AgentUsageMultiSeriesPoint[]
  series: readonly AgentUsageSeriesDef[]
}

export const AGENT_AUTOMATIONS_DESCRIPTION =
  'Automation runs started during the selected period. Each automation execution counts as one.'

export const AGENT_TOKENS_BREAKDOWN_DESCRIPTION =
  'Input and output tokens consumed by the agent during the selected period.'

export const AGENT_ACTIVITY_DESCRIPTION =
  'Messages, conversations, and tool calls during the selected period.'

export const AGENT_DOCS_HREF = '/docs/products/agent'

/** Metrics fetched from the console usage events API. */
export const AGENT_USAGE_FETCH_METRICS = [
  { id: 'tokens-input' as const, metric: AGENT_TOKENS_INPUT_EVENT_METRIC },
  { id: 'tokens-output' as const, metric: AGENT_TOKENS_OUTPUT_EVENT_METRIC },
  { id: 'messages' as const, metric: AGENT_MESSAGES_EVENT_METRIC },
  { id: 'conversations' as const, metric: AGENT_CONVERSATIONS_EVENT_METRIC },
  { id: 'tool-calls' as const, metric: AGENT_TOOL_CALLS_EVENT_METRIC },
  { id: 'automations' as const, metric: AGENT_AUTOMATIONS_EVENT_METRIC },
] as const

const AGENT_USAGE_EVENT_METRICS = AGENT_USAGE_FETCH_METRICS.map(
  (entry) => entry.metric,
) as AgentUsageEventMetric[]

export const AGENT_TOKENS_BREAKDOWN_SERIES: readonly AgentUsageSeriesDef[] = [
  {
    dataKey: 'input',
    label: 'Input',
    colorVar: '--chart-2',
    gradientId: 'usage-agent-tokens-input-gradient',
  },
  {
    dataKey: 'output',
    label: 'Output',
    colorVar: '--chart-brand',
    gradientId: 'usage-agent-tokens-output-gradient',
  },
] as const

export const AGENT_ACTIVITY_SERIES: readonly AgentUsageSeriesDef[] = [
  {
    dataKey: 'messages',
    label: 'Messages',
    colorVar: '--chart-2',
    gradientId: 'usage-agent-messages-gradient',
  },
  {
    dataKey: 'conversations',
    label: 'Conversations',
    colorVar: '--chart-brand',
    gradientId: 'usage-agent-conversations-gradient',
  },
  {
    dataKey: 'toolCalls',
    label: 'Tool calls',
    colorVar: '--chart-3',
    gradientId: 'usage-agent-tool-calls-gradient',
  },
] as const

export function formatAgentCountTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAgentCountValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactCount as formatAgentCountAxisValue,
  createCompactCountAxisTickFormatter as createAgentCountAxisTickFormatter,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

function mergeValuesByTime(
  groups: Models.UsageDataPoint[],
): Map<string, number> {
  const merged = new Map<string, number>()
  for (const point of groups) {
    if (!point.time) continue
    merged.set(point.time, (merged.get(point.time) ?? 0) + (point.value || 0))
  }
  return merged
}

function toUsageInterval(interval: UsageChartInterval): UsageInterval {
  if (interval === '15m') return UsageInterval.FifteenMinutes
  if (interval === '1d') return UsageInterval.OneDay
  return UsageInterval.OneHour
}

async function listConsoleUsageEventGroupsByMetric(params: {
  metrics: readonly string[]
  interval: UsageChartInterval
  startAt: string
  endAt: string
}): Promise<Map<string, Models.UsageDataPoint[]>> {
  if (params.metrics.length === 0) {
    return new Map()
  }

  const response = await sdk.forConsole.usage.listEvents({
    metrics: [...params.metrics],
    interval: toUsageInterval(params.interval),
    startAt: params.startAt,
    endAt: params.endAt,
  })

  const result = new Map<string, Models.UsageDataPoint[]>()
  for (const metric of params.metrics) {
    result.set(
      metric,
      response.metrics?.find((entry) => entry.metric === metric)?.points ?? [],
    )
  }
  return result
}

function buildSeriesOverview(
  chartPoints: AgentUsageChartPoint[],
  previousChartPoints: AgentUsageChartPoint[],
  comparisonMode: UsagePeriodComparisonMode,
): AgentUsageChartOverview {
  return {
    chartPoints,
    changePercent: computeChangePercent(
      sumUsageChartPointsForComparison(chartPoints, comparisonMode),
      sumUsageChartPoints(previousChartPoints),
    ),
  }
}

function mergeKeyedSeriesPoints(
  basePoints: AgentUsageChartPoint[],
  seriesByKey: Record<string, AgentUsageChartPoint[]>,
): AgentUsageMultiSeriesPoint[] {
  return basePoints.map((point, index) => {
    const values: Record<string, number> = {}
    let total = 0
    for (const [key, points] of Object.entries(seriesByKey)) {
      const value = points[index]?.total ?? 0
      values[key] = value
      total += value
    }
    return {
      date: point.date,
      day: point.day,
      total,
      ...values,
    }
  })
}

export type AccountAgentUsageOverview = {
  automations: AgentUsageChartOverview
  tokensBreakdown: AgentUsageMultiSeriesOverview
  activity: AgentUsageMultiSeriesOverview
}

function emptySingleOverview(): AgentUsageChartOverview {
  return { changePercent: 0, chartPoints: [] }
}

function emptyMultiOverview(
  series: readonly AgentUsageSeriesDef[],
): AgentUsageMultiSeriesOverview {
  return {
    changePercent: 0,
    chartPoints: [],
    multiSeriesPoints: [],
    series,
  }
}

function emptyAgentUsageOverview(): AccountAgentUsageOverview {
  return {
    automations: emptySingleOverview(),
    tokensBreakdown: emptyMultiOverview(AGENT_TOKENS_BREAKDOWN_SERIES),
    activity: emptyMultiOverview(AGENT_ACTIVITY_SERIES),
  }
}

/**
 * Account-scoped agent console usage (GET /v1/usage/events via console SDK).
 * Fetches agent metrics for the selected period plus a prior window for change %.
 */
export async function fetchAccountAgentUsageOverview(
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<AccountAgentUsageOverview> {
  const {
    from,
    to,
    previousFrom,
    previousTo,
    interval: resolvedInterval,
    comparisonMode,
  } = resolveOverviewUsagePeriod(dateRange, interval, 0)

  const currentByMetric = await listConsoleUsageEventGroupsByMetric({
    metrics: AGENT_USAGE_EVENT_METRICS,
    interval: resolvedInterval,
    startAt: from.toISOString(),
    endAt: to.toISOString(),
  })

  const previousByMetric =
    comparisonMode === 'prior_window'
      ? await listConsoleUsageEventGroupsByMetric({
          metrics: AGENT_USAGE_EVENT_METRICS,
          interval: resolvedInterval,
          startAt: previousFrom.toISOString(),
          endAt: previousTo.toISOString(),
        })
      : null

  const seriesFor = (metric: AgentUsageEventMetric) => {
    const chartPoints = fillChartPointsGaps(
      mergeValuesByTime(currentByMetric.get(metric) ?? []),
      from,
      to,
      resolvedInterval,
    )
    const previousChartPoints =
      comparisonMode === 'first_half'
        ? getUsageChartFirstHalfPoints(chartPoints)
        : fillChartPointsGaps(
            mergeValuesByTime(previousByMetric?.get(metric) ?? []),
            previousFrom,
            previousTo,
            resolvedInterval,
          )
    return { chartPoints, previousChartPoints }
  }

  const tokensInput = seriesFor(AGENT_TOKENS_INPUT_EVENT_METRIC)
  const tokensOutput = seriesFor(AGENT_TOKENS_OUTPUT_EVENT_METRIC)
  const messages = seriesFor(AGENT_MESSAGES_EVENT_METRIC)
  const conversations = seriesFor(AGENT_CONVERSATIONS_EVENT_METRIC)
  const toolCalls = seriesFor(AGENT_TOOL_CALLS_EVENT_METRIC)
  const automations = seriesFor(AGENT_AUTOMATIONS_EVENT_METRIC)

  const tokensBreakdownPoints = mergeChartPointsSeries([
    tokensInput.chartPoints,
    tokensOutput.chartPoints,
  ])
  const tokensBreakdownPrevious = mergeChartPointsSeries([
    tokensInput.previousChartPoints,
    tokensOutput.previousChartPoints,
  ])

  const activityPoints = mergeChartPointsSeries([
    messages.chartPoints,
    conversations.chartPoints,
    toolCalls.chartPoints,
  ])
  const activityPrevious = mergeChartPointsSeries([
    messages.previousChartPoints,
    conversations.previousChartPoints,
    toolCalls.previousChartPoints,
  ])

  const overview = emptyAgentUsageOverview()

  overview.automations = buildSeriesOverview(
    automations.chartPoints,
    automations.previousChartPoints,
    comparisonMode,
  )

  overview.tokensBreakdown = {
    ...buildSeriesOverview(
      tokensBreakdownPoints,
      tokensBreakdownPrevious,
      comparisonMode,
    ),
    multiSeriesPoints: mergeKeyedSeriesPoints(tokensBreakdownPoints, {
      input: tokensInput.chartPoints,
      output: tokensOutput.chartPoints,
    }),
    series: AGENT_TOKENS_BREAKDOWN_SERIES,
  }

  overview.activity = {
    ...buildSeriesOverview(activityPoints, activityPrevious, comparisonMode),
    multiSeriesPoints: mergeKeyedSeriesPoints(activityPoints, {
      messages: messages.chartPoints,
      conversations: conversations.chartPoints,
      toolCalls: toolCalls.chartPoints,
    }),
    series: AGENT_ACTIVITY_SERIES,
  }

  return overview
}
