import { useMemo } from 'react'
import { XAxis, YAxis, type XAxisProps, type YAxisProps } from 'recharts'
import { USAGE_CHART_Y_AXIS_WIDTH } from '@/lib/usage/chart-layout'
import type { DateRange } from 'react-day-picker'
import {
  CHART_X_AXIS_DEFAULT_DY,
  CHART_X_AXIS_DEFAULT_TICK,
  USAGE_CHART_X_AXIS_PADDING,
} from '@/lib/usage/chart-layout'
import {
  createSeriesChartXAxisTickFormatter,
  createUsageChartXAxisTickFormatter,
  resolveUsageChartXAxisTickIndices,
  USAGE_CHART_X_AXIS_MAX_TICKS,
} from '@/lib/usage/chart-axis'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'

type UsageChartXAxisPoint = {
  date: string
  day: Date
}

type UsageChartXAxisProps = {
  points: readonly UsageChartXAxisPoint[]
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  variant?: 'overview' | 'full'
  /**
   * @deprecated Ignored. The axis is index-based so tooltip/cursor stay aligned
   * when category labels repeat (e.g. HH:mm across days).
   */
  dataKey?: string
  tick?: XAxisProps['tick']
  dy?: number
}

export function UsageChartXAxis({
  points,
  dateRange,
  chartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  variant = 'full',
  tick = CHART_X_AXIS_DEFAULT_TICK,
  dy = CHART_X_AXIS_DEFAULT_DY,
}: UsageChartXAxisProps) {
  const resolvedInterval = useMemo(
    () => resolveUsageChartIntervalForRange(chartInterval, dateRange),
    [chartInterval, dateRange],
  )
  const { from: rangeFrom, to: rangeTo } = useMemo(
    () => resolveUsageDateBounds(dateRange),
    [dateRange],
  )
  const tickIndices = useMemo(
    () =>
      resolveUsageChartXAxisTickIndices(
        points.length,
        resolvedInterval,
        variant,
      ),
    [points.length, resolvedInterval, variant],
  )
  const tickFormatter = useMemo(
    () =>
      createUsageChartXAxisTickFormatter(
        points,
        tickIndices,
        resolvedInterval,
        rangeFrom,
        rangeTo,
      ),
    [points, tickIndices, resolvedInterval, rangeFrom, rangeTo],
  )

  return (
    <XAxis
      // No dataKey: Recharts uses point indices for the category domain and
      // tooltip activeIndex. Label-based lookup (dataKey="date") returns the
      // wrong point when labels repeat or padding shifts the active label.
      axisLine={false}
      tickLine={false}
      tick={tick}
      dy={dy}
      padding={USAGE_CHART_X_AXIS_PADDING}
      interval={0}
      tickFormatter={tickFormatter}
    />
  )
}

type SeriesChartXAxisProps = {
  pointCount: number
  maxTicks?: number
  /**
   * Optional tick labels by data index. Prefer this over `dataKey` so the axis
   * can stay index-based (correct hover/tooltip) while still showing labels.
   */
  labels?: readonly string[]
  /**
   * @deprecated Prefer `labels`. When set without `labels`, kept for backward
   * compatibility but can misalign tooltips if values repeat.
   */
  dataKey?: string
  tick?: XAxisProps['tick']
  dy?: number
  height?: number
  padding?: XAxisProps['padding']
  formatLabel?: (value: string, index: number) => string
}

type UsageChartYAxisProps = {
  tickFormatter?: (value: number) => string
  domain?: YAxisProps['domain']
  width?: number
  tick?: YAxisProps['tick']
  /** Force integer ticks — avoids duplicate labels on small count axes. */
  allowDecimals?: boolean
  /** Set to render a second axis (dual-axis charts). */
  yAxisId?: YAxisProps['yAxisId']
  orientation?: YAxisProps['orientation']
  /** Tick color — defaults to inherited currentColor; set for a series-tinted axis. */
  tickFill?: string
}

/** Shared Y-axis for usage/overview time-series charts. */
export function UsageChartYAxis({
  tickFormatter,
  domain,
  width = USAGE_CHART_Y_AXIS_WIDTH,
  tick,
  allowDecimals,
  yAxisId,
  orientation,
  tickFill = 'currentColor',
}: UsageChartYAxisProps) {
  const tickProps =
    typeof tick === 'object' && tick != null && !Array.isArray(tick)
      ? tick
      : {}

  return (
    <YAxis
      {...(yAxisId != null ? { yAxisId } : {})}
      {...(orientation != null ? { orientation } : {})}
      axisLine={false}
      tickLine={false}
      width={width}
      domain={domain}
      allowDecimals={allowDecimals}
      tickFormatter={tickFormatter}
      tick={{
        fill: tickFill,
        fontSize: 10,
        textAnchor: orientation === 'right' ? 'start' : 'end',
        ...tickProps,
      }}
    />
  )
}

export function SeriesChartXAxis({
  pointCount,
  maxTicks = USAGE_CHART_X_AXIS_MAX_TICKS,
  labels,
  dataKey: dataKeyProp,
  tick = CHART_X_AXIS_DEFAULT_TICK,
  dy = CHART_X_AXIS_DEFAULT_DY,
  height,
  padding = USAGE_CHART_X_AXIS_PADDING,
  formatLabel,
}: SeriesChartXAxisProps) {
  const tickFormatter = useMemo(
    () =>
      createSeriesChartXAxisTickFormatter(
        pointCount,
        maxTicks,
        formatLabel,
        labels,
      ),
    [pointCount, maxTicks, formatLabel, labels],
  )

  // Prefer index-based domain when `labels` is provided so tooltip/cursor use
  // activeIndex instead of findEntryInArray(label) - which returns the wrong
  // point when labels repeat (e.g. HH:mm across multiple days).
  const useIndexDomain = labels != null
  const dataKey = useIndexDomain ? undefined : (dataKeyProp ?? 'date')

  return (
    <XAxis
      {...(dataKey != null ? { dataKey } : {})}
      axisLine={false}
      tickLine={false}
      tick={tick}
      dy={dy}
      height={height}
      padding={padding}
      interval={0}
      tickFormatter={tickFormatter}
    />
  )
}
