'use client'

import { useId, useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
} from '@/components/pages/projects/$projectId/overview/chart-panel'
import { WafRuleAction } from '@appwrite.io/console'
import { getFirewallActionChartColor } from '@/lib/firewall/actions'
import { formatFirewallSolveTime } from '@/lib/firewall/usage'
import type { FirewallActionActivityPoint } from '@/lib/firewall/types'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ACTIVITY_CHART_MARGIN = { top: 12, right: 4, left: 0, bottom: 0 } as const
const ACTIVITY_CHART_Y_AXIS_WIDTH = 40
// Series colors from the shared firewall action palette so they match the main
// firewall chart legend: solves = Challenge (violet), avg time = Rate limited (amber).
const SOLVES_COLOR = getFirewallActionChartColor(WafRuleAction.Challenge)
const SOLVE_TIME_COLOR = getFirewallActionChartColor(WafRuleAction.RateLimit)

export type FirewallActionActivityChartProps = {
  series: readonly FirewallActionActivityPoint[]
  valueLabel: string
  /** Add the avg solve time line on a second (ms) axis. */
  showSolveTime?: boolean
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  height?: number
  className?: string
  emptyLabel?: string
}

export function FirewallActionActivityChart({
  series,
  valueLabel,
  showSolveTime = false,
  dateRange,
  chartInterval,
  height = OVERVIEW_CHART_HEIGHT,
  className,
  emptyLabel,
}: FirewallActionActivityChartProps) {
  const t = useT()
  const gradientId = `firewall-action-activity-${useId().replace(/:/g, '')}`
  const solveTimeLabel = t('Avg solve time')

  const chartPoints = useMemo(
    () => series.map((point) => ({ date: point.date, day: point.day })),
    [series],
  )
  const countAxisMax = useMemo(
    () => series.reduce((max, point) => Math.max(max, point.value), 0),
    [series],
  )
  const countTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(countAxisMax),
    [countAxisMax],
  )

  const hasData = Boolean(dateRange && series.length > 0)

  return (
    <div className={cn(FORCE_LTR_CLASS, className)}>
      {showSolveTime && hasData ? (
        <div className="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 px-2">
          <LegendDot color={SOLVES_COLOR} label={valueLabel} />
          <LegendDot color={SOLVE_TIME_COLOR} label={solveTimeLabel} />
        </div>
      ) : null}
      <div
        className={cn(overviewChartPanelBodyClass, FORCE_LTR_CLASS)}
        style={{ height }}
      >
        <div className={overviewChartPanelChartAreaClass}>
        <div className={overviewChartPanelChartFillClass}>
          {hasData ? (
            <ResponsiveContainer
              {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
              minHeight={height}
            >
              <ComposedChart data={[...series]} margin={ACTIVITY_CHART_MARGIN}>
                <defs>
                  <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SOLVES_COLOR} stopOpacity={0.2} />
                    <stop offset="100%" stopColor={SOLVES_COLOR} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="hsl(var(--border))"
                  vertical={false}
                />
                <UsageChartXAxis
                  points={chartPoints}
                  dateRange={dateRange}
                  chartInterval={chartInterval}
                  variant="overview"
                />
                {/* Left axis: solves (count). */}
                <UsageChartYAxis
                  yAxisId="count"
                  tickFormatter={countTickFormatter}
                  width={ACTIVITY_CHART_Y_AXIS_WIDTH}
                  allowDecimals={false}
                  domain={[0, (dataMax: number) => Math.ceil(dataMax * 1.05) || 1]}
                />
                {/* Right axis: avg solve time (ms). */}
                {showSolveTime ? (
                  <UsageChartYAxis
                    yAxisId="time"
                    orientation="right"
                    tickFormatter={formatFirewallSolveTime}
                    width={ACTIVITY_CHART_Y_AXIS_WIDTH}
                    domain={[
                      0,
                      (dataMax: number) => Math.ceil(dataMax * 1.1) || 1,
                    ]}
                  />
                ) : null}
                <Tooltip
                  isAnimationActive={false}
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const point = payload[0]
                      ?.payload as FirewallActionActivityPoint
                    return (
                      <div className="rounded-md border border-border bg-popover px-3 py-2">
                        <p className="mb-1.5 text-[11px] text-muted-foreground">
                          {point.fullDate}
                        </p>
                        <div className="space-y-1">
                          <div className="flex justify-between gap-6 text-[11px]">
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                              <ChartSeriesDot color={SOLVES_COLOR} />
                              {valueLabel}
                            </span>
                            <span className="font-medium tabular-nums text-foreground">
                              {point.value.toLocaleString()}
                            </span>
                          </div>
                          {showSolveTime ? (
                            <div className="flex justify-between gap-6 text-[11px]">
                              <span className="flex items-center gap-1.5 text-muted-foreground">
                                <ChartSeriesDot color={SOLVE_TIME_COLOR} />
                                {solveTimeLabel}
                              </span>
                              <span className="font-medium tabular-nums text-foreground">
                                {formatFirewallSolveTime(
                                  point.avgSolveTimeMs ?? 0,
                                )}
                              </span>
                            </div>
                          ) : null}
                        </div>
                      </div>
                    )
                  }}
                />
                <Area
                  yAxisId="count"
                  type="monotone"
                  dataKey="value"
                  name={valueLabel}
                  stroke={SOLVES_COLOR}
                  strokeWidth={2}
                  fill={`url(#${gradientId})`}
                  dot={false}
                  {...CHART_ANIMATION_DISABLED}
                />
                {showSolveTime ? (
                  <Line
                    yAxisId="time"
                    type="monotone"
                    dataKey="avgSolveTimeMs"
                    name={solveTimeLabel}
                    stroke={SOLVE_TIME_COLOR}
                    strokeWidth={2}
                    dot={false}
                    {...CHART_ANIMATION_DISABLED}
                  />
                ) : null}
              </ComposedChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
              {emptyLabel ?? t('No activity for this period')}
            </div>
          )}
        </div>
        </div>
      </div>
    </div>
  )
}

function LegendDot({ color, label }: { color: string; label: string }) {
  return (
    <div className="flex items-center gap-1.5">
      <span
        className="h-2 w-2 rounded-full"
        style={{ backgroundColor: color }}
      />
      <span className="text-[12px] text-muted-foreground">{label}</span>
    </div>
  )
}
