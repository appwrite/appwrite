'use client'

import { useId, useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
} from '@/components/pages/projects/$projectId/overview/chart-panel'
import { formatFirewallSolveTime } from '@/lib/firewall/usage'
import type { FirewallActionActivityPoint } from '@/lib/firewall/types'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ACTIVITY_CHART_MARGIN = { top: 12, right: 8, left: 0, bottom: 0 } as const
const ACTIVITY_CHART_Y_AXIS_WIDTH = 36

export type FirewallActionActivityChartProps = {
  series: readonly FirewallActionActivityPoint[]
  /** Series color (per-action palette) + tooltip label for the count. */
  color: string
  valueLabel: string
  /** Show an avg solve time row in the tooltip (challenge action). */
  showSolveTime?: boolean
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  height?: number
  className?: string
  emptyLabel?: string
}

export function FirewallActionActivityChart({
  series,
  color,
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

  const chartPoints = useMemo(
    () => series.map((point) => ({ date: point.date, day: point.day })),
    [series],
  )
  const chartAxisMax = useMemo(
    () => series.reduce((max, point) => Math.max(max, point.value), 0),
    [series],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const hasData = Boolean(dateRange && series.length > 0)

  return (
    <div
      className={cn(overviewChartPanelBodyClass, FORCE_LTR_CLASS, className)}
      style={{ height }}
    >
      <div className={overviewChartPanelChartAreaClass}>
        <div className={overviewChartPanelChartFillClass}>
          {hasData ? (
            <ResponsiveContainer
              {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
              minHeight={height}
            >
              <AreaChart data={[...series]} margin={ACTIVITY_CHART_MARGIN}>
                <defs>
                  <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity={0.2} />
                    <stop offset="100%" stopColor={color} stopOpacity={0} />
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
                <UsageChartYAxis
                  tickFormatter={yAxisTickFormatter}
                  width={ACTIVITY_CHART_Y_AXIS_WIDTH}
                  domain={[0, (dataMax: number) => Math.ceil(dataMax * 1.05) || 1]}
                />
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
                            <span className="text-muted-foreground">
                              {valueLabel}
                            </span>
                            <span className="font-medium tabular-nums text-foreground">
                              {point.value.toLocaleString()}
                            </span>
                          </div>
                          {showSolveTime ? (
                            <div className="flex justify-between gap-6 text-[11px]">
                              <span className="text-muted-foreground">
                                {t('Avg solve time')}
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
                  type="monotone"
                  dataKey="value"
                  name={valueLabel}
                  stroke={color}
                  strokeWidth={2}
                  fill={`url(#${gradientId})`}
                  dot={false}
                  {...CHART_ANIMATION_DISABLED}
                />
              </AreaChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
              {emptyLabel ?? t('No activity for this period')}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
