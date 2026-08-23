'use client'

import { useMemo, type ReactNode } from 'react'
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
import { createCompactBytesAxisTickFormatter } from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  resolveBandwidthDualChartDisplay,
  resolveBandwidthStackedYAxisDomain,
  type BandwidthDualChartPoint,
} from '@/lib/usage/bandwidth-events'
import { OVERVIEW_CHART_HEIGHT } from '../../overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useUsageFilters } from '../usage-filters-context'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { Skeleton } from '@/components/ui/skeleton'
import { UsageMetricCardFooter, UsageMetricCardShell } from './UsageMetricCard'
import { UsageSectionChartError } from './UsageSectionChartError'
import { UsageChartBrushReferenceArea } from './UsageChartBrushReferenceArea'

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

function ChartSkeleton({ label }: { label: string }) {
  return (
    <div
      role="status"
      aria-label={label}
      className="relative w-full shrink-0"
      style={{ height: OVERVIEW_CHART_HEIGHT }}
    >
      <Skeleton className="absolute inset-0 rounded-md" />
      <span className="sr-only">{label}</span>
    </div>
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

type InboundOutboundUsageChartCardProps = {
  title: string
  description: string
  docsHref?: string
  total: number
  changePercent: number
  dualChartPoints: BandwidthDualChartPoint[]
  isLoading: boolean
  isError: boolean
  error?: unknown
  /** @deprecated Prefer `error` */
  queryError?: unknown
  errorTitle: string
  errorMessage: string
  inboundGradientId: string
  outboundGradientId: string
  formatTotal: (value: number) => string
  formatValue: (value: number) => string
  onRetry?: () => void
}

export function InboundOutboundUsageChartCard({
  title,
  description,
  docsHref,
  total,
  changePercent,
  dualChartPoints,
  isLoading,
  isError,
  error,
  queryError,
  errorTitle,
  errorMessage,
  inboundGradientId,
  outboundGradientId,
  formatTotal,
  formatValue,
  onRetry,
}: InboundOutboundUsageChartCardProps) {
  const t = useT()
  const { dateRange, chartInterval, onDateRangeChange } = useUsageFilters()
  const chartData = useMemo(
    () =>
      dualChartPoints.map((point) => ({
        date: point.date,
        fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
        inbound: point.inbound,
        outbound: point.outbound,
        total: point.total,
      })),
    [dualChartPoints],
  )
  const {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName,
    chartProps,
  } = useUsageChartBrushSelect({
    points: dualChartPoints,
    chartInterval,
    onDateRangeChange,
  })

  const formattedTotal = formatTotal(total)
  const changeLabel =
    changePercent > 0
      ? `+${changePercent}%`
      : changePercent < 0
        ? `${changePercent}%`
        : '0%'
  const { showDualSeries, axisMax: bandwidthAxisMax } = useMemo(
    () => resolveBandwidthDualChartDisplay(chartData),
    [chartData],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactBytesAxisTickFormatter(bandwidthAxisMax),
    [bandwidthAxisMax],
  )
  const bandwidthYAxisDomain = useMemo(
    () =>
      showDualSeries
        ? resolveBandwidthStackedYAxisDomain(bandwidthAxisMax)
        : undefined,
    [showDualSeries, bandwidthAxisMax],
  )

  return (
    <UsageMetricCardShell>
      <div className="shrink-0 flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <h3 className="text-[14px] font-medium text-foreground">
            {t(title)}
          </h3>

          <div className={metricHeaderClass}>
            {isLoading ? (
              <ChartMetricHeaderSkeleton />
            ) : (
              <>
                <span className="text-[24px] font-semibold tabular-nums text-foreground">
                  {formattedTotal}
                </span>
                {!isError && dualChartPoints.length > 0 ? (
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

        {showDualSeries && !isLoading ? (
          <div className="flex shrink-0 flex-wrap items-center gap-3">
            <div className="flex items-center gap-1.5">
              <div
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: 'var(--chart-2)' }}
              />
              <span className="text-[11px] text-muted-foreground">
                {t('Inbound')}
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <div
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: 'var(--chart-brand)' }}
              />
              <span className="text-[11px] text-muted-foreground">
                {t('Outbound')}
              </span>
            </div>
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
          <ChartSkeleton label={t('Loading usage data')} />
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
                    <linearGradient
                      id={inboundGradientId}
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor="var(--chart-2)"
                        stopOpacity={0.15}
                      />
                      <stop
                        offset="100%"
                        stopColor="var(--chart-2)"
                        stopOpacity={0}
                      />
                    </linearGradient>
                    <linearGradient
                      id={outboundGradientId}
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor="var(--chart-brand)"
                        stopOpacity={0.2}
                      />
                      <stop
                        offset="100%"
                        stopColor="var(--chart-brand)"
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
                    points={dualChartPoints}
                    dateRange={dateRange}
                    chartInterval={chartInterval}
                  />
                  <UsageChartYAxis
                    tickFormatter={yAxisTickFormatter}
                    domain={bandwidthYAxisDomain}
                  />
                  <Tooltip
                    cursor={!isSelecting}
                    content={({ active, payload }) => {
                      if (isSelecting || !active || !payload?.length)
                        return null
                      const data = payload[0].payload as {
                        fullDate: string
                        inbound: number
                        outbound: number
                      }
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1 text-[11px] text-muted-foreground">
                            {data.fullDate}
                          </p>
                          {showDualSeries ? (
                            <div className="space-y-0.5">
                              <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                                <ChartSeriesDot color="var(--chart-2)" />
                                {formatValue(data.inbound)}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t('inbound')}
                                </span>
                              </p>
                              <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                                <ChartSeriesDot color="var(--chart-brand)" />
                                {formatValue(data.outbound)}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t('outbound')}
                                </span>
                              </p>
                              <p className="border-t border-border pt-1 text-[13px] font-medium text-foreground">
                                {formatValue(data.inbound + data.outbound)}{' '}
                                <span className="font-normal text-muted-foreground">
                                  {t('total')}
                                </span>
                              </p>
                            </div>
                          ) : (
                            <p className="text-[13px] font-medium text-foreground">
                              {formatValue(data.inbound + data.outbound)}
                            </p>
                          )}
                        </div>
                      )
                    }}
                  />
                  {showDualSeries ? (
                    <>
                      <Area
                        type="monotone"
                        dataKey="inbound"
                        name="Inbound"
                        stackId="bandwidth"
                        stroke="var(--chart-2)"
                        strokeWidth={2}
                        fill={`url(#${inboundGradientId})`}
                        {...CHART_ANIMATION_DISABLED}
                      />
                      <Area
                        type="monotone"
                        dataKey="outbound"
                        name="Outbound"
                        stackId="bandwidth"
                        stroke="var(--chart-brand)"
                        strokeWidth={2}
                        fill={`url(#${outboundGradientId})`}
                        {...CHART_ANIMATION_DISABLED}
                      />
                    </>
                  ) : (
                    <Area
                      type="monotone"
                      dataKey="total"
                      stroke="var(--chart-brand)"
                      strokeWidth={2}
                      fill={`url(#${outboundGradientId})`}
                      name="Bandwidth"
                      {...CHART_ANIMATION_DISABLED}
                    />
                  )}
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
