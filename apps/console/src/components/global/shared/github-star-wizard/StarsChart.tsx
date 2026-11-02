'use client'

import { useId, useMemo } from 'react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import {
  SeriesChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import type { GitHubStarHistoryPoint } from '@/lib/marketing/github-stars-history'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  createCompactCountAxisTickFormatter,
  getChartSeriesMax,
} from '@/lib/usage/format-metric'
import { cn } from '@/lib/utils'

const CHART_COLOR = 'var(--chart-brand)'

type StarsChartPoint = {
  year: string
  fullDate: string
  value: number
}

/** Stars-over-time chart in the product's usage-chart style. */
export function StarsChart({ data }: { data: GitHubStarHistoryPoint[] }) {
  const t = useT()
  const gradientId = useId().split(':').join('')

  const chartData = useMemo<StarsChartPoint[]>(
    () =>
      data.map((point) => {
        const date = new Date(`${point.date}T00:00:00Z`)
        return {
          year: point.date.slice(0, 4),
          fullDate: formatLocalizedDate(date, 'MMM yyyy'),
          value: point.stars,
        }
      }),
    [data],
  )

  const yearLabels = useMemo(
    () => chartData.map((point) => point.year),
    [chartData],
  )
  const chartAxisMax = useMemo(() => getChartSeriesMax(chartData), [chartData])
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )
  const lastIndex = chartData.length - 1
  const chartLabel = t('GitHub stars over time')

  return (
    <div
      className={cn('h-[200px] w-full text-muted-foreground', FORCE_LTR_CLASS)}
      role="img"
      aria-label={chartLabel}
    >
      <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
        {/* Extra right margin keeps the last year tick inside the SVG. */}
        <AreaChart
          data={chartData}
          margin={{ ...USAGE_CHART_MARGIN, right: 14 }}
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={CHART_COLOR} stopOpacity={0.2} />
              <stop offset="100%" stopColor={CHART_COLOR} stopOpacity={0} />
            </linearGradient>
          </defs>
          <CartesianGrid
            strokeDasharray="3 3"
            stroke="var(--border)"
            vertical={false}
          />
          <SeriesChartXAxis pointCount={chartData.length} labels={yearLabels} />
          <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
          <Tooltip
            cursor={{ stroke: 'var(--border)', strokeDasharray: '3 3' }}
            content={({ active, payload }) => {
              const point = payload?.[0]?.payload as StarsChartPoint | undefined
              if (!active || !point) return null
              return (
                <div className="rounded-md border border-border bg-popover px-3 py-2">
                  <p className="mb-1 text-[11px] text-muted-foreground">
                    {point.fullDate}
                  </p>
                  <p className="text-[13px] font-medium tabular-nums text-foreground">
                    {point.value.toLocaleString()}{' '}
                    <span className="font-normal text-muted-foreground">
                      {t('stars')}
                    </span>
                  </p>
                </div>
              )
            }}
          />
          <Area
            type="monotone"
            dataKey="value"
            stroke={CHART_COLOR}
            strokeWidth={2}
            fill={`url(#${gradientId})`}
            activeDot={{
              r: 3.5,
              fill: CHART_COLOR,
              stroke: 'var(--popover)',
              strokeWidth: 2,
            }}
            dot={(props: { cx?: number; cy?: number; index?: number }) =>
              props.index === lastIndex &&
              props.cx != null &&
              props.cy != null ? (
                <circle
                  key="last"
                  cx={props.cx}
                  cy={props.cy}
                  r={3}
                  fill={CHART_COLOR}
                  stroke="var(--popover)"
                  strokeWidth={2}
                />
              ) : (
                <g key={props.index} />
              )
            }
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}
