'use client'

import { useMemo, type ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { AlertCircle } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  OVERVIEW_CHART_HEIGHT,
  overviewChartPanelErrorClass,
} from '../../overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { useOptionalUsageFilters } from '../usage-filters-context'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { UsageChartErrorMessage } from '../../shared/UsageChartErrorMessage'
import {
  isUsageAddonNotFoundError,
  resolveUsageChartErrorCopy,
  shouldSuppressUsageChartRetry,
} from '@/lib/usage/usage-history-errors'
import { DEFAULT_USAGE_LOG_RETENTION_DAYS } from '@/lib/usage/usage-log-retention'
import { UsagePremiumGeoDBCurtain } from './UsagePremiumGeoDBCurtain'
import {
  createUsageChartAxisTickFormatter,
  getChartSeriesMax,
  type UsageChartAxisFormat,
} from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { UsageMetricCardFooter, UsageMetricCardShell } from './UsageMetricCard'
import { UsageChartBrushReferenceArea } from './UsageChartBrushReferenceArea'

const usageMetricHeaderClass =
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

function ChartSkeleton({ label }: { label: string }) {
  return (
    <div
      role="status"
      aria-label={label}
      className="relative w-full shrink-0"
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      <Skeleton className="absolute inset-0 rounded-lg" />
      <span className="sr-only">{label}</span>
    </div>
  )
}

function UsageChartArea({ children }: { children: ReactNode }) {
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

type UsageTimeSeriesChartCardProps = {
  title: string
  description: string
  unitLabel: string
  chartGradientId: string
  total: number
  changePercent: number
  chartPoints: { date: string; day: Date; total: number }[]
  isLoading: boolean
  isError: boolean
  error?: unknown
  /** @deprecated Prefer `error` */
  queryError?: unknown
  errorTitle: string
  errorMessage: string
  formatTotal: (value: number) => string
  formatValue: (value: number) => string
  axisFormat?: UsageChartAxisFormat
  onRetry?: () => void
  /** Renders chart header + body only (no card shell or footer). */
  embedded?: boolean
  className?: string
  docsHref?: string
  /** Optional overrides when UsageFiltersProvider is not mounted (e.g. DB monitor). */
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  onDateRangeChange?: (dateRange: DateRange | undefined) => void
}

export function UsageTimeSeriesChartCard({
  title,
  description,
  unitLabel,
  chartGradientId,
  total,
  changePercent,
  chartPoints,
  isLoading,
  isError,
  error,
  queryError,
  errorTitle,
  errorMessage,
  formatTotal,
  formatValue,
  axisFormat = 'count',
  onRetry,
  embedded = false,
  className,
  docsHref,
  dateRange: dateRangeProp,
  chartInterval: chartIntervalProp,
  onDateRangeChange: onDateRangeChangeProp,
}: UsageTimeSeriesChartCardProps) {
  const t = useT()
  const usageFilters = useOptionalUsageFilters()
  const dateRange = dateRangeProp ?? usageFilters?.dateRange
  const chartInterval =
    chartIntervalProp ??
    usageFilters?.chartInterval ??
    DEFAULT_USAGE_CHART_INTERVAL
  const onDateRangeChange =
    onDateRangeChangeProp ?? usageFilters?.onDateRangeChange
  const resolvedQueryError = error ?? queryError
  const resolvedErrorCopy = useMemo(
    () =>
      resolveUsageChartErrorCopy(
        resolvedQueryError,
        usageFilters?.usageLogRetentionDays ?? DEFAULT_USAGE_LOG_RETENTION_DAYS,
        { title: errorTitle, message: errorMessage },
      ),
    [
      resolvedQueryError,
      usageFilters?.usageLogRetentionDays,
      errorTitle,
      errorMessage,
    ],
  )
  const showRetry =
    !!onRetry && !shouldSuppressUsageChartRetry(resolvedErrorCopy)
  const showGeoDbCurtain =
    isUsageAddonNotFoundError(resolvedQueryError) ||
    resolvedErrorCopy.isAddonNotFound
  const chartData = useMemo(
    () =>
      chartPoints.map((point) => ({
        date: point.date,
        fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
        value: point.total,
      })),
    [chartPoints],
  )
  const {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName,
    chartProps,
  } = useUsageChartBrushSelect({
    points: chartPoints,
    chartInterval,
    onDateRangeChange,
  })

  const chartColor = 'var(--chart-brand)'
  const formattedTotal = formatTotal(total)
  const changeLabel =
    changePercent > 0
      ? `+${changePercent}%`
      : changePercent < 0
        ? `${changePercent}%`
        : '0%'
  const chartAxisMax = useMemo(() => getChartSeriesMax(chartData), [chartData])
  const yAxisTickFormatter = useMemo(
    () => createUsageChartAxisTickFormatter(axisFormat, chartAxisMax),
    [axisFormat, chartAxisMax],
  )

  const chartContent = (
    <>
      <div
        className={cn(
          'shrink-0 flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between',
          embedded && 'border-b-0',
        )}
      >
        <div className="min-w-0 flex-1">
          <h3 className="text-[14px] font-medium text-foreground">
            {t(title)}
          </h3>

          <div className={usageMetricHeaderClass}>
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
                {!isError && chartPoints.length > 0 ? (
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
      </div>

      <div className="flex flex-1 flex-col p-4">
        {isError ? (
          <UsageChartArea>
            {showGeoDbCurtain ? (
              <UsagePremiumGeoDBCurtain
                className="absolute inset-0"
                onEnabled={onRetry}
              >
                <div className="h-full w-full rounded-lg bg-muted/20" />
              </UsagePremiumGeoDBCurtain>
            ) : (
              <div className={overviewChartPanelErrorClass}>
                <AlertCircle className="h-8 w-8 shrink-0 text-muted-foreground" />
                <div className="max-w-sm">
                  <p className="text-[13px] font-medium text-foreground">
                    {t(resolvedErrorCopy.title)}
                  </p>
                  <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                    <UsageChartErrorMessage copy={resolvedErrorCopy} />
                  </p>
                </div>
                {showRetry ? (
                  <Button variant="outline" size="sm" onClick={onRetry}>
                    {t('Try again')}
                  </Button>
                ) : null}
              </div>
            )}
          </UsageChartArea>
        ) : isLoading ? (
          <ChartSkeleton label={t('Loading usage data')} />
        ) : chartData.length === 0 ? (
          <UsageChartArea>
            <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
              {t('No data for this date range')}
            </div>
          </UsageChartArea>
        ) : (
          <UsageChartArea>
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
                    <linearGradient
                      id={chartGradientId}
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={chartColor}
                        stopOpacity={0.2}
                      />
                      <stop
                        offset="100%"
                        stopColor={chartColor}
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
                  />
                  <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
                  <Tooltip
                    isAnimationActive={false}
                    cursor={!isSelecting}
                    content={({ active, payload }) => {
                      if (isSelecting || !active || !payload?.length)
                        return null
                      const data = payload[0].payload as {
                        fullDate: string
                        value: number
                      }
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1 text-[11px] text-muted-foreground">
                            {data.fullDate}
                          </p>
                          <p className="text-[13px] font-medium text-foreground">
                            {formatValue(data.value)}{' '}
                            <span className="font-normal text-muted-foreground">
                              {t(unitLabel)}
                            </span>
                          </p>
                        </div>
                      )
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey="value"
                    stroke={chartColor}
                    strokeWidth={2}
                    fill={`url(#${chartGradientId})`}
                    name={title}
                    dot={false}
                    {...CHART_ANIMATION_DISABLED}
                  />
                  <UsageChartBrushReferenceArea
                    left={brushLeft}
                    right={brushRight}
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          </UsageChartArea>
        )}
      </div>
    </>
  )

  if (embedded) {
    return (
      <div className={cn('min-w-0 flex flex-col', className)}>
        {chartContent}
      </div>
    )
  }

  return (
    <UsageMetricCardShell>
      {chartContent}
      <UsageMetricCardFooter description={description} docsHref={docsHref} />
    </UsageMetricCardShell>
  )
}
