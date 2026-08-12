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
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
} from '@/components/pages/projects/$projectId/overview/chart-panel'
import type { FirewallImpactPoint } from '@/lib/firewall/types'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Same green as TrafficOverview requests / RuleImpactPreview matched series. */
export const FIREWALL_IMPACT_MATCHED_COLOR = '#10b981'

const IMPACT_CHART_MARGIN = { top: 12, right: 8, left: 0, bottom: 0 } as const
const IMPACT_CHART_Y_AXIS_WIDTH = 36

export type FirewallImpactChartProps = {
  series: readonly FirewallImpactPoint[]
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  height?: number
  className?: string
  /** Empty-state copy when series has no points. */
  emptyLabel?: string
}

export function FirewallImpactChart({
  series,
  dateRange,
  chartInterval,
  height = OVERVIEW_CHART_HEIGHT,
  className,
  emptyLabel,
}: FirewallImpactChartProps) {
  const t = useT()
  const gradientId = `firewall-impact-matched-${useId().replace(/:/g, '')}`

  const chartPoints = useMemo(
    () => series.map((point) => ({ date: point.date, day: point.day })),
    [series],
  )
  const chartAxisMax = useMemo(
    () => series.reduce((max, point) => Math.max(max, point.total), 0),
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
              <AreaChart data={[...series]} margin={IMPACT_CHART_MARGIN}>
                <defs>
                  <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                    <stop
                      offset="0%"
                      stopColor={FIREWALL_IMPACT_MATCHED_COLOR}
                      stopOpacity={0.2}
                    />
                    <stop
                      offset="100%"
                      stopColor={FIREWALL_IMPACT_MATCHED_COLOR}
                      stopOpacity={0}
                    />
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
                  width={IMPACT_CHART_Y_AXIS_WIDTH}
                  domain={[0, (dataMax: number) => Math.ceil(dataMax * 1.05) || 1]}
                />
                <Tooltip
                  isAnimationActive={false}
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const point = payload[0]?.payload as FirewallImpactPoint
                    return (
                      <div className="rounded-md border border-border bg-popover px-3 py-2">
                        <p className="mb-1.5 text-[11px] text-muted-foreground">
                          {point.fullDate}
                        </p>
                        <div className="space-y-1">
                          <div className="flex justify-between gap-6 text-[11px]">
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                              <ChartSeriesDot color="hsl(var(--muted-foreground))" />
                              {t('Total traffic')}
                            </span>
                            <span className="font-medium tabular-nums text-foreground">
                              {point.total.toLocaleString()}
                            </span>
                          </div>
                          <div className="flex justify-between gap-6 text-[11px]">
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                              <ChartSeriesDot color={FIREWALL_IMPACT_MATCHED_COLOR} />
                              {t('Matched by rule')}
                            </span>
                            <span className="font-medium tabular-nums text-foreground">
                              {point.matched.toLocaleString()}
                            </span>
                          </div>
                        </div>
                      </div>
                    )
                  }}
                />
                <Area
                  type="monotone"
                  dataKey="total"
                  name={t('Total traffic')}
                  stroke="hsl(var(--muted-foreground))"
                  strokeWidth={1.5}
                  strokeDasharray="4 4"
                  fill="transparent"
                  dot={false}
                  {...CHART_ANIMATION_DISABLED}
                />
                <Area
                  type="monotone"
                  dataKey="matched"
                  name={t('Matched by rule')}
                  stroke={FIREWALL_IMPACT_MATCHED_COLOR}
                  strokeWidth={2}
                  fill={`url(#${gradientId})`}
                  dot={false}
                  {...CHART_ANIMATION_DISABLED}
                />
              </AreaChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
              {emptyLabel ?? t('No traffic data for this period')}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
