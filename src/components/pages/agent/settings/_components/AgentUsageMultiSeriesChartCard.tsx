'use client'

import { useMemo, type ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  createAgentCountAxisTickFormatter,
  type AgentUsageMultiSeriesPoint,
  type AgentUsageSeriesDef,
} from '@/lib/usage/agent-usage'
import {
  resolveBandwidthStackedYAxisDomain,
} from '@/lib/usage/bandwidth-events'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  OVERVIEW_CHART_HEIGHT,
} from '@/components/pages/projects/$projectId/overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import { UsageChartXAxis, UsageChartYAxis } from '@/components/global/shared/ChartXAxis'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { Skeleton } from '@/components/ui/skeleton'
import {
  UsageMetricCardFooter,
  UsageMetricCardShell,
} from '@/components/pages/projects/$projectId/usage/_components/UsageMetricCard'
import { UsageSectionChartError } from '@/components/pages/projects/$projectId/usage/_components/UsageSectionChartError'
import { UsageChartBrushReferenceArea } from '@/components/pages/projects/$projectId/usage/_components/UsageChartBrushReferenceArea'

const metricHeaderClass =
  'mt-2 min-h-[52px] flex flex-wrap items-baseline gap-x-2 gap-y-1'

function ChartMetricHeaderSkeleton() {
  return (
    <>
      <Skeleton className="h-7 w-28 shrink-0 rounded-sm" />
      <Skeleton className="h-4 w-[4.5rem] shrink-0 rounded-sm" />
      <Skeleton className="h-3 w-44 max-w-full shrink-0 rounded-sm" />
    </>
  )
}

function ChartSkeleton() {
  return (
    <Skeleton
      className="w-full shrink-0 rounded-md"
      style={{ height: OVERVIEW_CHART_HEIGHT }}
      aria-hidden
    />
  )
}

function ChartArea({ children }: { children: ReactNode }) {
  return (
    <div
      className={cn(
        'relative w-full shrink-0 text-muted-foreground',
        FORCE_LTR_CLASS,
      )}
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      {children}
    </div>
  )
}

type AgentUsageMultiSeriesChartCardProps = {
  title: string
  description: string
  docsHref?: string
  unitLabel: string
  total: number
  changePercent: number
  multiSeriesPoints: AgentUsageMultiSeriesPoint[]
  series: readonly AgentUsageSeriesDef[]
  isLoading: boolean
  isError: boolean
  error?: unknown
  queryError?: unknown
  errorTitle: string
  errorMessage: string
  formatTotal: (value: number) => string
  formatValue: (value: number) => string
  onRetry?: () => void
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  onDateRangeChange?: (dateRange: DateRange | undefined) => void
  stackId?: string
}

export function AgentUsageMultiSeriesChartCard({
  title,
  description,
  docsHref,
  unitLabel,
  total,
  changePercent,
  multiSeriesPoints,
  series,
  isLoading,
  isError,
  error,
  queryError,
  errorTitle,
  errorMessage,
  formatTotal,
  formatValue,
  onRetry,
  dateRange,
  chartInterval,
  onDateRangeChange,
  stackId = 'agent-usage',
}: AgentUsageMultiSeriesChartCardProps) {
  const t = useT()
  const chartData = useMemo(
    () =>
      multiSeriesPoints.map((point) => {
        const row: Record<string, string | number | Date> = {
          ...point,
          fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
        }
        return row
      }),
    [multiSeriesPoints],
  )
  const {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName,
    chartProps,
  } = useUsageChartBrushSelect({
    points: multiSeriesPoints,
    chartInterval: chartInterval ?? DEFAULT_USAGE_CHART_INTERVAL,
    onDateRangeChange,
  })

  const formattedTotal = formatTotal(total)
  const changeLabel =
    changePercent > 0
      ? `+${changePercent}%`
      : changePercent < 0
        ? `${changePercent}%`
        : '0%'

  const axisMax = useMemo(
    () =>
      chartData.reduce((max, point) => {
        const stacked = series.reduce(
          (sum, entry) => sum + (Number(point[entry.dataKey]) || 0),
          0,
        )
        return Math.max(max, stacked, Number(point.total) || 0)
      }, 0),
    [chartData, series],
  )
  const yAxisTickFormatter = useMemo(
    () => createAgentCountAxisTickFormatter(axisMax),
    [axisMax],
  )
  const yAxisDomain = useMemo(
    () => resolveBandwidthStackedYAxisDomain(axisMax),
    [axisMax],
  )
  const showSeries = !isLoading && multiSeriesPoints.length > 0 && axisMax > 0

  return (
    <UsageMetricCardShell>
      <div className="shrink-0 flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <h3 className="text-[14px] font-medium text-foreground">{t(title)}</h3>

          <div className={metricHeaderClass}>
            {isLoading ? (
              <ChartMetricHeaderSkeleton />
            ) : (
              <>
                <span className="text-[24px] font-semibold tabular-nums text-foreground">
                  {formattedTotal}
                </span>
                <span className="text-[13px] text-muted-foreground">
                  {t(unitLabel)}
                </span>
                {!isError && multiSeriesPoints.length > 0 ? (
                  <span
                    className={cn(
                      'text-[12px] font-medium tabular-nums',
                      changePercent > 0 &&
                        'text-emerald-600 dark:text-emerald-400',
                      changePercent < 0 && 'text-amber-600 dark:text-amber-400',
                      changePercent === 0 && 'text-muted-foreground',
                    )}
                  >
                    {changeLabel} {t('vs previous period')}
                  </span>
                ) : !isLoading ? (
                  <span
                    className="invisible text-[12px] font-medium tabular-nums"
                    aria-hidden
                  >
                    0% {t('vs previous period')}
                  </span>
                ) : null}
              </>
            )}
          </div>
        </div>

        {showSeries ? (
          <div className="flex shrink-0 flex-wrap items-center gap-3">
            {series.map((entry) => (
              <div key={entry.dataKey} className="flex items-center gap-1.5">
                <div
                  className="h-2 w-2 rounded-full"
                  style={{ backgroundColor: `var(${entry.colorVar})` }}
                />
                <span className="text-[11px] text-muted-foreground">
                  {t(entry.label)}
                </span>
              </div>
            ))}
          </div>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col p-4">
        {isError ? (
          <ChartArea>
            <UsageSectionChartError
              error={error ?? queryError}
              errorTitle={errorTitle}
              errorMessage={errorMessage}
              onRetry={onRetry}
            />
          </ChartArea>
        ) : isLoading ? (
          <ChartSkeleton />
        ) : chartData.length === 0 ? (
          <ChartArea>
            <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
              {t('No data for this date range')}
            </div>
          </ChartArea>
        ) : (
          <ChartArea>
            <div
              className={surfaceClassName}
              aria-label={
                canSelect
                  ? t('Drag on the chart to select a date range')
                  : undefined
              }
            >
              <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
                <AreaChart
                  data={chartData}
                  margin={USAGE_CHART_MARGIN}
                  {...chartProps}
                >
                  <defs>
                    {series.map((entry) => (
                      <linearGradient
                        key={entry.gradientId}
                        id={entry.gradientId}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={`var(${entry.colorVar})`}
                          stopOpacity={0.2}
                        />
                        <stop
                          offset="100%"
                          stopColor={`var(${entry.colorVar})`}
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
                    points={multiSeriesPoints}
                    dateRange={dateRange}
                    chartInterval={chartInterval}
                  />
                  <UsageChartYAxis
                    tickFormatter={yAxisTickFormatter}
                    domain={yAxisDomain}
                  />
                  <Tooltip
                    cursor={!isSelecting}
                    content={({ active, payload }) => {
                      if (isSelecting || !active || !payload?.length) return null
                      const data = payload[0]?.payload as Record<
                        string,
                        string | number
                      >
                      if (!data) return null
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1 text-[11px] text-muted-foreground">
                            {String(data.fullDate ?? '')}
                          </p>
                          <div className="space-y-0.5">
                            {series.map((entry) => (
                              <p
                                key={entry.dataKey}
                                className="flex items-center gap-1.5 text-[13px] font-medium text-foreground"
                              >
                                <ChartSeriesDot
                                  color={`var(${entry.colorVar})`}
                                />
                                {formatValue(Number(data[entry.dataKey]) || 0)}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t(entry.label)}
                                </span>
                              </p>
                            ))}
                            <p className="border-t border-border pt-1 text-[13px] font-medium text-foreground">
                              {formatValue(Number(data.total) || 0)}{' '}
                              <span className="font-normal text-muted-foreground">
                                {t('total')}
                              </span>
                            </p>
                          </div>
                        </div>
                      )
                    }}
                  />
                  {series.map((entry) => (
                    <Area
                      key={entry.dataKey}
                      type="monotone"
                      dataKey={entry.dataKey}
                      name={entry.label}
                      stackId={stackId}
                      stroke={`var(${entry.colorVar})`}
                      strokeWidth={2}
                      fill={`url(#${entry.gradientId})`}
                      {...CHART_ANIMATION_DISABLED}
                    />
                  ))}
                  <UsageChartBrushReferenceArea
                    left={brushLeft}
                    right={brushRight}
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </ChartArea>
        )}
      </div>

      <UsageMetricCardFooter description={description} docsHref={docsHref} />
    </UsageMetricCardShell>
  )
}
