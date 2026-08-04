'use client'

import { useId, useMemo, type ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import { WafRuleAction } from '@appwrite.io/console'
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
import { getFirewallActionChartColor } from '@/lib/firewall/actions'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import type { FirewallChallengePoint } from '@/lib/usage/firewall-events'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CHALLENGE_CHART_MARGIN = { top: 8, right: 12, left: 0, bottom: 4 } as const
const CHALLENGE_SOLVED_COLOR = getFirewallActionChartColor(
  WafRuleAction.Challenge,
)
const SOLVE_TIME_COLOR = '#6366f1'

/** Compact ms/s label for solve-time axis ticks, tooltip, and header. */
export function formatSolveTime(ms: number): string {
  if (ms <= 0) return '0ms'
  if (ms >= 1000) return `${(ms / 1000).toFixed(1)}s`
  return `${Math.round(ms)}ms`
}

type ChallengeMiniChartProps = {
  title: string
  headerValue: string
  data: readonly FirewallChallengePoint[]
  dataKey: 'solved' | 'avgSolveTimeMs'
  color: string
  gradientId: string
  seriesLabel: string
  valueFormatter: (value: number) => string
  yAxisTickFormatter: (value: number) => string
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  height: number
  emptyLabel: string
}

function ChallengeMiniChart({
  title,
  headerValue,
  data,
  dataKey,
  color,
  gradientId,
  seriesLabel,
  valueFormatter,
  yAxisTickFormatter,
  dateRange,
  chartInterval,
  height,
  emptyLabel,
}: ChallengeMiniChartProps): ReactNode {
  const chartPoints = useMemo(
    () => data.map((point) => ({ date: point.date, day: point.day })),
    [data],
  )
  const hasData = Boolean(dateRange && data.length > 0)

  return (
    <div className="min-w-0">
      <div className="mb-2 flex items-baseline justify-between gap-x-2">
        <span className="text-[12px] text-muted-foreground">{title}</span>
        <span className="text-[16px] font-semibold tabular-nums text-foreground">
          {headerValue}
        </span>
      </div>
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
                <AreaChart data={[...data]} margin={CHALLENGE_CHART_MARGIN}>
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
                    domain={[
                      0,
                      (dataMax: number) => Math.ceil(dataMax * 1.08) || 1,
                    ]}
                  />
                  <Tooltip
                    isAnimationActive={false}
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const point = payload[0]?.payload as FirewallChallengePoint
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1.5 text-[11px] text-muted-foreground">
                            {point.fullDate}
                          </p>
                          <div className="flex items-center justify-between gap-6">
                            <span className="text-[11px] text-muted-foreground">
                              {seriesLabel}
                            </span>
                            <span className="text-[13px] font-medium tabular-nums text-foreground">
                              {valueFormatter(Number(point[dataKey] ?? 0))}
                            </span>
                          </div>
                        </div>
                      )
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey={dataKey}
                    name={seriesLabel}
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
                {emptyLabel}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

export type FirewallChallengeChartsProps = {
  data: readonly FirewallChallengePoint[]
  totalSolved: number
  avgSolveTimeMs: number
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  height?: number
}

export function FirewallChallengeCharts({
  data,
  totalSolved,
  avgSolveTimeMs,
  dateRange,
  chartInterval,
  height = OVERVIEW_CHART_HEIGHT,
}: FirewallChallengeChartsProps) {
  const t = useT()
  const idSuffix = useId().replace(/:/g, '')

  const solvedAxisMax = useMemo(
    () => data.reduce((max, point) => Math.max(max, point.solved), 0),
    [data],
  )
  const solvedTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(solvedAxisMax),
    [solvedAxisMax],
  )

  return (
    <div className="grid gap-x-8 gap-y-6 sm:grid-cols-2">
      <ChallengeMiniChart
        title={t('Challenge solves')}
        headerValue={totalSolved.toLocaleString()}
        data={data}
        dataKey="solved"
        color={CHALLENGE_SOLVED_COLOR}
        gradientId={`firewall-challenge-solved-${idSuffix}`}
        seriesLabel={t('Solved')}
        valueFormatter={(value) => value.toLocaleString()}
        yAxisTickFormatter={solvedTickFormatter}
        dateRange={dateRange}
        chartInterval={chartInterval}
        height={height}
        emptyLabel={t('No challenge data for this period')}
      />
      <ChallengeMiniChart
        title={t('Avg solve time')}
        headerValue={formatSolveTime(avgSolveTimeMs)}
        data={data}
        dataKey="avgSolveTimeMs"
        color={SOLVE_TIME_COLOR}
        gradientId={`firewall-solve-time-${idSuffix}`}
        seriesLabel={t('Avg solve time')}
        valueFormatter={formatSolveTime}
        yAxisTickFormatter={formatSolveTime}
        dateRange={dateRange}
        chartInterval={chartInterval}
        height={height}
        emptyLabel={t('No challenge data for this period')}
      />
    </div>
  )
}
