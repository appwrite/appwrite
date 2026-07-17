import { useMemo, useState, type ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { cn } from '@/lib/utils'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  resolveUsageChartIntervalForRange,
  DEFAULT_USAGE_CHART_INTERVAL,
} from '@/lib/usage/chart-interval'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
} from '../overview/chart-panel'
import {
  buildFirewallTrafficSeries,
  getDefaultFirewallRange,
  mockFirewallAnalytics,
} from '@/lib/firewall/mock-usage'
import { useT } from '@/lib/i18n/translate'

const SERIES = [
  {
    key: 'requests' as const,
    label: 'Requests',
    color: '#10b981',
    gradientId: 'firewall-requests-fill',
  },
  {
    key: 'denied' as const,
    label: 'Denied',
    color: 'var(--destructive)',
    gradientId: 'firewall-denied-fill',
  },
  {
    key: 'rateLimited' as const,
    label: 'Rate limited',
    color: 'var(--chart-4)',
    gradientId: 'firewall-rate-limited-fill',
  },
  {
    key: 'bypassed' as const,
    label: 'Bypassed',
    color: '#3b82f6',
    gradientId: 'firewall-bypassed-fill',
  },
  {
    key: 'redirected' as const,
    label: 'Redirected',
    color: 'var(--chart-3)',
    gradientId: 'firewall-redirected-fill',
  },
]

interface StatCardProps {
  label: string
  value: string | number
  change?: number
  trend?: 'up' | 'down'
}

function MetricTile({ label, value, change, trend }: StatCardProps) {
  return (
    <div className="min-w-0">
      <p className="text-[12px] text-muted-foreground">{label}</p>
      <div className="mt-0.5 flex items-baseline gap-x-2">
        <span className="text-[20px] font-semibold tabular-nums text-foreground">
          {typeof value === 'number' ? value.toLocaleString() : value}
        </span>
        {change !== undefined ? (
          <span
            className={cn(
              'text-[12px] font-medium tabular-nums',
              trend === 'up' && 'text-emerald-600 dark:text-emerald-400',
              trend === 'down' && 'text-amber-600 dark:text-amber-400',
              !trend && 'text-muted-foreground',
            )}
          >
            {change > 0 ? '+' : ''}
            {change}%
          </span>
        ) : null}
      </div>
    </div>
  )
}

function ChartArea({ children }: { children: ReactNode }) {
  return (
    <div
      className={cn(overviewChartPanelBodyClass, FORCE_LTR_CLASS)}
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      <div className={overviewChartPanelChartAreaClass}>
        <div className={overviewChartPanelChartFillClass}>{children}</div>
      </div>
    </div>
  )
}

export function TrafficOverview() {
  const t = useT()
  const analytics = mockFirewallAnalytics
  const [dateRange, setDateRange] = useState<DateRange | undefined>(() =>
    getDefaultFirewallRange(),
  )

  const chartInterval = useMemo(() => {
    if (!dateRange?.from) return DEFAULT_USAGE_CHART_INTERVAL
    return resolveUsageChartIntervalForRange(
      DEFAULT_USAGE_CHART_INTERVAL,
      dateRange,
    )
  }, [dateRange])

  const chartData = useMemo(() => {
    if (!dateRange?.from) return []
    const to = dateRange.to ?? dateRange.from
    return buildFirewallTrafficSeries(dateRange.from, to)
  }, [dateRange])

  const chartPoints = useMemo(
    () => chartData.map((point) => ({ date: point.date, day: point.day })),
    [chartData],
  )

  const chartAxisMax = useMemo(
    () =>
      chartData.reduce(
        (max, point) =>
          Math.max(
            max,
            point.requests,
            point.denied,
            point.bypassed,
            point.rateLimited,
            point.redirected,
          ),
        0,
      ),
    [chartData],
  )

  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const denyRate =
    analytics.totalRequests > 0
      ? ((analytics.totalDenied / analytics.totalRequests) * 100).toFixed(1)
      : '0.0'

  const metrics = [
    {
      label: t('Denied'),
      value: analytics.totalDenied,
      change: analytics.deniedChange,
      trend: (analytics.deniedChange > 0 ? 'up' : 'down') as const,
    },
    {
      label: t('Bypassed'),
      value: analytics.totalBypassed,
      change: analytics.bypassedChange,
      trend: (analytics.bypassedChange > 0 ? 'up' : 'down') as const,
    },
    {
      label: t('Rate limited'),
      value: analytics.totalRateLimited,
      change: analytics.rateLimitedChange,
      trend: (analytics.rateLimitedChange > 0 ? 'up' : 'down') as const,
    },
    {
      label: t('Redirected'),
      value: analytics.totalRedirected,
      change: analytics.redirectedChange,
      trend: (analytics.redirectedChange > 0 ? 'up' : 'down') as const,
    },
    {
      label: t('Deny rate'),
      value: `${denyRate}%`,
      change: analytics.denyRateChange,
      trend: (analytics.denyRateChange > 0 ? 'up' : 'down') as const,
    },
  ]

  return (
    <div className="w-full">
      <div className="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span className="text-[24px] font-semibold tabular-nums text-foreground">
              {analytics.totalRequests.toLocaleString()}
            </span>
            <span className="text-[13px] text-muted-foreground">
              {t('requests')}
            </span>
            <span
              className={cn(
                'text-[12px] font-medium tabular-nums',
                analytics.requestsChange > 0 &&
                  'text-emerald-600 dark:text-emerald-400',
                analytics.requestsChange < 0 &&
                  'text-amber-600 dark:text-amber-400',
                analytics.requestsChange === 0 && 'text-muted-foreground',
              )}
            >
              {analytics.requestsChange > 0 ? '+' : ''}
              {analytics.requestsChange}% {t('vs previous period')}
            </span>
          </div>
        </div>
        <DateRangePicker
          dateRange={dateRange}
          onDateRangeChange={setDateRange}
          className="h-9 shrink-0"
        />
      </div>

      <div className="px-4 pb-4 pt-4 sm:px-6">
        <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
          {SERIES.map((series) => (
            <div key={series.key} className="flex items-center gap-1.5">
              <span
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: series.color }}
              />
              <span className="text-[12px] text-muted-foreground">
                {t(series.label)}
              </span>
            </div>
          ))}
        </div>

        <ChartArea>
          <ResponsiveContainer
            {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
            minHeight={OVERVIEW_CHART_HEIGHT}
          >
            <AreaChart
              data={chartData}
              margin={{ top: 8, right: 12, left: 0, bottom: 4 }}
            >
              <defs>
                {SERIES.map((series) => (
                  <linearGradient
                    key={series.gradientId}
                    id={series.gradientId}
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
                ))}
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
              />
              <UsageChartYAxis
                tickFormatter={yAxisTickFormatter}
                domain={[0, (dataMax: number) => Math.ceil(dataMax * 1.08) || 1]}
              />
              <Tooltip
                isAnimationActive={false}
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const point = payload[0]?.payload as {
                    fullDate?: string
                  }
                  return (
                    <div className="rounded-md border border-border bg-popover px-3 py-2">
                      <p className="mb-1.5 text-[11px] text-muted-foreground">
                        {point.fullDate}
                      </p>
                      <div className="space-y-1">
                        {payload.map((entry) => (
                          <div
                            key={String(entry.dataKey)}
                            className="flex items-center justify-between gap-6"
                          >
                            <span className="text-[11px] text-muted-foreground">
                              {entry.name}
                            </span>
                            <span className="text-[13px] font-medium tabular-nums text-foreground">
                              {Number(entry.value ?? 0).toLocaleString()}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )
                }}
              />
              {SERIES.map((series) => (
                <Area
                  key={series.key}
                  type="monotone"
                  dataKey={series.key}
                  name={t(series.label)}
                  stroke={series.color}
                  strokeWidth={2}
                  fill={`url(#${series.gradientId})`}
                  dot={false}
                  {...CHART_ANIMATION_DISABLED}
                />
              ))}
            </AreaChart>
          </ResponsiveContainer>
        </ChartArea>
      </div>

      <div className="grid grid-cols-2 border-y border-border xl:grid-cols-5">
        {metrics.map((metric, index) => {
          const isLast = index === metrics.length - 1
          return (
            <div
              key={metric.label}
              className={cn(
                'px-4 py-3 sm:px-6',
                !isLast && 'border-b border-border xl:border-b-0',
                index % 2 === 0 && !isLast && 'border-e border-border',
                !isLast && 'xl:border-e xl:border-border',
                isLast && 'col-span-2 xl:col-span-1',
              )}
            >
              <MetricTile
                label={metric.label}
                value={metric.value}
                change={metric.change}
                trend={metric.trend}
              />
            </div>
          )
        })}
      </div>
    </div>
  )
}
