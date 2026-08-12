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
import {
  FIREWALL_TRAFFIC_SERIES,
  getFirewallTrafficSeriesTotals,
  sortFirewallTrafficSeriesByValueAsc,
  type FirewallTrafficSeriesKey,
} from '@/lib/firewall/traffic-series'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import type { FirewallTrafficPoint } from '@/lib/usage/firewall-events'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TRAFFIC_CHART_MARGIN = { top: 8, right: 12, left: 0, bottom: 4 } as const

export type FirewallTrafficChartProps = {
  data: readonly FirewallTrafficPoint[]
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  height?: number
  className?: string
  emptyLabel?: string
  /** Unique suffix so multiple charts on one page do not clash gradient ids. */
  gradientSuffix?: string
}

export function FirewallTrafficChart({
  data,
  dateRange,
  chartInterval,
  height = OVERVIEW_CHART_HEIGHT,
  className,
  emptyLabel,
  gradientSuffix,
}: FirewallTrafficChartProps) {
  const t = useT()
  const reactId = useId().replace(/:/g, '')
  const suffix = gradientSuffix ?? reactId

  const chartPoints = useMemo(
    () => data.map((point) => ({ date: point.date, day: point.day })),
    [data],
  )
  const seriesTotals = useMemo(
    () => getFirewallTrafficSeriesTotals(data),
    [data],
  )
  const seriesByValueAsc = useMemo(
    () => sortFirewallTrafficSeriesByValueAsc(seriesTotals),
    [seriesTotals],
  )
  const chartAxisMax = useMemo(
    () =>
      data.reduce(
        (max, point) =>
          Math.max(
            max,
            point.requests +
              point.denied +
              point.challenged +
              point.rateLimited +
              point.redirected,
          ),
        0,
      ),
    [data],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const hasData = Boolean(dateRange && data.length > 0)

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
              <AreaChart data={[...data]} margin={TRAFFIC_CHART_MARGIN}>
                <defs>
                  {seriesByValueAsc.map((series) => {
                    const gradientId = `${series.gradientId}-${suffix}`
                    return (
                      <linearGradient
                        key={gradientId}
                        id={gradientId}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={series.color}
                          stopOpacity={0.2}
                        />
                        <stop
                          offset="100%"
                          stopColor={series.color}
                          stopOpacity={0}
                        />
                      </linearGradient>
                    )
                  })}
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
                  domain={[
                    0,
                    (dataMax: number) => Math.ceil(dataMax * 1.08) || 1,
                  ]}
                />
                <Tooltip
                  isAnimationActive={false}
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const point = payload[0]?.payload as FirewallTrafficPoint

                    const seriesValue = (
                      entry: (typeof payload)[number],
                    ): number => {
                      const key = String(entry.dataKey ?? '') as FirewallTrafficSeriesKey
                      if (
                        key === 'requests' ||
                        key === 'denied' ||
                        key === 'challenged' ||
                        key === 'rateLimited' ||
                        key === 'redirected'
                      ) {
                        return Number(point[key] ?? 0)
                      }
                      if (Array.isArray(entry.value)) {
                        const [from, to] = entry.value as [number, number]
                        return Math.abs(Number(to) - Number(from)) || 0
                      }
                      return Number(entry.value ?? 0)
                    }

                    const sortedPayload = [...payload].sort(
                      (a, b) => seriesValue(a) - seriesValue(b),
                    )

                    return (
                      <div className="rounded-md border border-border bg-popover px-3 py-2">
                        <p className="mb-1.5 text-[11px] text-muted-foreground">
                          {point.fullDate}
                        </p>
                        <div className="space-y-1">
                          {sortedPayload.map((entry) => {
                            const seriesKey = String(
                              entry.dataKey ?? '',
                            ) as FirewallTrafficSeriesKey
                            const seriesColor =
                              FIREWALL_TRAFFIC_SERIES.find(
                                (series) => series.key === seriesKey,
                              )?.color ??
                              (typeof entry.color === 'string'
                                ? entry.color
                                : undefined)
                            return (
                              <div
                                key={String(entry.dataKey)}
                                className="flex items-center justify-between gap-6"
                              >
                                <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                  {seriesColor ? (
                                    <ChartSeriesDot color={seriesColor} />
                                  ) : null}
                                  {entry.name}
                                </span>
                                <span className="text-[13px] font-medium tabular-nums text-foreground">
                                  {seriesValue(entry).toLocaleString()}
                                </span>
                              </div>
                            )
                          })}
                        </div>
                      </div>
                    )
                  }}
                />
                {seriesByValueAsc.map((series) => {
                  const gradientId = `${series.gradientId}-${suffix}`
                  return (
                    <Area
                      key={series.key}
                      type="monotone"
                      stackId="firewall-traffic"
                      dataKey={series.key}
                      name={t(series.label)}
                      stroke={
                        seriesTotals[series.key] > 0
                          ? series.color
                          : 'transparent'
                      }
                      strokeWidth={2}
                      fill={`url(#${gradientId})`}
                      dot={false}
                      {...CHART_ANIMATION_DISABLED}
                    />
                  )
                })}
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
