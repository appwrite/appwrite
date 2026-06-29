import { useMemo } from 'react'
import { XAxis, type XAxisProps } from 'recharts'
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
  dataKey?: string
  tick?: XAxisProps['tick']
  dy?: number
}

export function UsageChartXAxis({
  points,
  dateRange,
  chartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  variant = 'full',
  dataKey = 'date',
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
      dataKey={dataKey}
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
  dataKey?: string
  tick?: XAxisProps['tick']
  dy?: number
  height?: number
  padding?: XAxisProps['padding']
  formatLabel?: (value: string, index: number) => string
}

export function SeriesChartXAxis({
  pointCount,
  maxTicks = USAGE_CHART_X_AXIS_MAX_TICKS,
  dataKey = 'date',
  tick = CHART_X_AXIS_DEFAULT_TICK,
  dy = CHART_X_AXIS_DEFAULT_DY,
  height,
  padding = USAGE_CHART_X_AXIS_PADDING,
  formatLabel,
}: SeriesChartXAxisProps) {
  const tickFormatter = useMemo(
    () => createSeriesChartXAxisTickFormatter(pointCount, maxTicks, formatLabel),
    [pointCount, maxTicks, formatLabel],
  )

  return (
    <XAxis
      dataKey={dataKey}
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
