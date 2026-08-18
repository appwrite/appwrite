import { memo, useMemo, type ReactNode } from 'react'
import { Area, AreaChart, ResponsiveContainer, Tooltip } from 'recharts'
import { useParams } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { OverviewChartTabId } from '@/lib/overview-chart-tabs'
import { OverviewViewAllUsageLink } from './OverviewViewAllUsageLink'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  OVERVIEW_CHART_HEIGHT,
  USAGE_CHART_MARGIN,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelChartFillClass,
  overviewChartPanelEmptyClass,
  overviewChartPanelHeaderClass,
  overviewChartPanelHeaderActionsClass,
} from './chart-panel'
import { OverviewChartPanelError } from './OverviewChartPanelError'
import { GbHoursUnitInfo } from './GbHoursUnitInfo'
import { MetricValueWithUnit } from './MetricValueWithUnit'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { TooltipProvider } from '@/components/ui/tooltip'
import type { DateRange } from 'react-day-picker'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  createUsageChartAxisTickFormatter,
  formatCompactBytes,
  type UsageChartAxisFormat,
} from '@/lib/usage/format-metric'
import {
  resolveBandwidthDualChartDisplay,
  resolveBandwidthStackedYAxisDomain,
} from '@/lib/usage/bandwidth-events'
import {
  fillChartPointsGaps,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import { UsageChartXAxis, UsageChartYAxis } from '@/components/global/shared/ChartXAxis'
import { USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'

type MetricType = OverviewChartTabId

type ChartPoint = {
  date: string
  day: Date
  total: number
  inbound?: number
  outbound?: number
}

interface RequestsChartProps {
  className?: string
  title?: string
  metric?: MetricType
  dateRange?: DateRange
  chartInterval?: UsageChartInterval
  chartData?: ChartPoint[]
  isLoading?: boolean
  isError?: boolean
  onRetry?: () => void
  /** When set, chart error state shows Upgrade plan instead of retry. */
  upgradeOrgId?: string | null
  errorTitle?: string
  errorMessage?: ReactNode
  formatValue?: (value: number) => string
  /** When false, the chart body is not rendered (inactive overview tab). */
  isPanelVisible?: boolean
  /** Hide the link to the full usage page (e.g. when already on /usage). */
  showViewAllLink?: boolean
}

function getOverviewChartAxisFormat(metric: MetricType): UsageChartAxisFormat {
  if (metric === 'bandwidth') return 'bytes'
  if (metric === 'gbhours') return 'gbhours'
  return 'count'
}

/** Soft greyscale placeholder - visible on the real chart canvas. */
const SKELETON_CHART_STROKE = 'hsl(var(--muted-foreground) / 0.4)'
const SKELETON_CHART_FILL = 'hsl(var(--muted-foreground))'
const SKELETON_CHART_FILL_TOP_OPACITY = 0.14
const SKELETON_CHART_FILL_BOTTOM_OPACITY = 0.02
const SKELETON_WAVE = [
  0.42, 0.58, 0.51, 0.68, 0.59, 0.72, 0.64, 0.7, 0.55, 0.74, 0.62, 0.69,
] as const

function getSkeletonPeak(metric: MetricType): number {
  switch (metric) {
    case 'bandwidth':
      return 80_000_000
    case 'requests':
      return 80_000
    case 'executions':
      return 8_000
    case 'gbhours':
      return 160
    default:
      return 1_000
  }
}

function buildSkeletonChartData(
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  metric: MetricType = 'requests',
): ChartPoint[] {
  const { from, to } = resolveUsageDateBounds(dateRange)
  const resolvedInterval = resolveUsageChartIntervalForRange(interval, dateRange)
  const shell = fillChartPointsGaps(new Map(), from, to, resolvedInterval)
  const points =
    shell.length > 0
      ? shell
      : buildFallbackSkeletonShell(from, to, resolvedInterval)
  const peak = getSkeletonPeak(metric)

  return points.map((point, index) => ({
    date: point.date,
    day: point.day,
    total: Math.round(peak * SKELETON_WAVE[index % SKELETON_WAVE.length]),
  }))
}

function coarsenUsageChartInterval(
  interval: UsageChartInterval,
): UsageChartInterval {
  if (interval === '15m') return '1h'
  if (interval === '1h') return '1d'
  return '1d'
}

function buildFallbackSkeletonShell(
  from: Date,
  to: Date,
  interval: UsageChartInterval,
): UsageChartPoint[] {
  const shell = fillChartPointsGaps(
    new Map(),
    from,
    to,
    coarsenUsageChartInterval(interval),
  )
  if (shell.length > 0) return shell

  return SKELETON_WAVE.map((weight, index) => ({
    date: `${index + 1}`,
    day: new Date(from.getTime() + index * 60 * 60 * 1000),
    total: 0,
  }))
}

/** Stable gradient ids per metric (avoid remounting on parent re-render). */
function getOverviewChartGradientIds(metric: MetricType) {
  return {
    areaGradientId: `overview-chart-gradient-${metric}`,
    skeletonGradientId: `overview-chart-gradient-skeleton-${metric}`,
    inboundGradientId: 'overview-chart-gradient-inbound',
    outboundGradientId: 'overview-chart-gradient-outbound',
  }
}

interface CustomTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    dataKey: string
    payload: ChartPoint
  }>
  formatValue?: (value: number) => string
  metric?: MetricType
}

const FormattedMetricValue = ({ value }: { value: string }) => (
  <MetricValueWithUnit
    value={value}
    className="text-[12px] font-medium text-foreground"
    unitClassName="text-muted-foreground"
  />
)

const CustomTooltip = ({
  active,
  payload,
  formatValue,
  metric = 'requests',
}: CustomTooltipProps) => {
  const t = useT()
  if (active && payload && payload.length) {
    const data = payload[0].payload
    const format = formatValue ?? ((value: number) => value.toLocaleString())

    if (
      metric === 'bandwidth' &&
      typeof data.inbound === 'number' &&
      typeof data.outbound === 'number'
    ) {
      return (
        <div className="rounded-lg border border-border bg-popover px-3 py-2.5">
          <p className="mb-2 text-[12px] font-medium text-foreground">
            {data.date}
          </p>
          <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-6">
              <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                <ChartSeriesDot color="var(--chart-2)" />
                {t('Inbound')}
              </span>
              <span className="text-[12px] font-medium text-foreground">
                <FormattedMetricValue value={format(data.inbound)} />
              </span>
            </div>
            <div className="flex items-center justify-between gap-6">
              <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                <ChartSeriesDot color="var(--chart-brand)" />
                {t('Outbound')}
              </span>
              <span className="text-[12px] font-medium text-foreground">
                <FormattedMetricValue value={format(data.outbound)} />
              </span>
            </div>
            <div className="flex items-center justify-between gap-6 border-t border-border pt-1.5">
              <span className="text-[11px] text-muted-foreground">{t('Total')}</span>
              <span className="text-[12px] font-medium text-foreground">
                <FormattedMetricValue value={format(data.total)} />
              </span>
            </div>
          </div>
        </div>
      )
    }

    return (
      <div className="rounded-lg border border-border bg-popover px-3 py-2.5">
        <p className="mb-1 text-[12px] font-medium text-foreground">
          {data.date}
        </p>
        <div className="flex items-center justify-between gap-6">
          <span className="text-[11px] text-muted-foreground">
            {metric === 'bandwidth' ? t('Bandwidth') : t('Value')}
          </span>
          <span className="text-[12px] font-medium text-foreground">
            <FormattedMetricValue value={format(data.total)} />
          </span>
        </div>
      </div>
    )
  }
  return null
}

export const RequestsChart = memo(function RequestsChart({
  className,
  title,
  metric = 'requests',
  dateRange,
  chartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  chartData: chartDataProp = [],
  isLoading = false,
  isError = false,
  onRetry,
  upgradeOrgId,
  errorTitle = OVERVIEW_BANDWIDTH_ERROR.title,
  errorMessage = OVERVIEW_BANDWIDTH_ERROR.message,
  formatValue,
  isPanelVisible = true,
  showViewAllLink = true,
}: RequestsChartProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })

  const chartData = chartDataProp
  const skeletonChartData = useMemo(
    () => buildSkeletonChartData(dateRange, chartInterval, metric),
    [dateRange, chartInterval, metric],
  )
  const showBandwidthDualSeries =
    !isLoading &&
    metric === 'bandwidth' &&
    resolveBandwidthDualChartDisplay(chartData).showDualSeries

  const showChartSkeleton = isLoading
  const showEmptyState = !isLoading && chartData.length === 0
  const {
    areaGradientId,
    skeletonGradientId,
    inboundGradientId,
    outboundGradientId,
  } = getOverviewChartGradientIds(metric)
  const valueFormatter =
    formatValue ??
    (metric === 'requests' || metric === 'executions'
      ? (value: number) => String(value)
      : metric === 'gbhours'
        ? (value: number) => String(value)
        : (value: number) => formatCompactBytes(value, { compact: true }))
  const activeChartData = showChartSkeleton ? skeletonChartData : chartData
  const resolvedChartInterval = useMemo(
    () => resolveUsageChartIntervalForRange(chartInterval, dateRange),
    [chartInterval, dateRange],
  )
  const bandwidthAxisMax = useMemo(() => {
    if (activeChartData.length === 0) return 0
    if (metric === 'bandwidth') {
      return resolveBandwidthDualChartDisplay(activeChartData).axisMax
    }
    return activeChartData.reduce(
      (max, point) => Math.max(max, point.total),
      0,
    )
  }, [activeChartData, metric])
  const bandwidthYAxisDomain = useMemo(() => {
    if (metric !== 'bandwidth' || !showBandwidthDualSeries) return undefined
    return resolveBandwidthStackedYAxisDomain(bandwidthAxisMax)
  }, [metric, showBandwidthDualSeries, bandwidthAxisMax])
  const yAxisTickFormatter = useMemo(() => {
    const axisFormat = getOverviewChartAxisFormat(metric)
    return createUsageChartAxisTickFormatter(axisFormat, bandwidthAxisMax)
  }, [bandwidthAxisMax, metric])
  const tooltipContent = (
    <CustomTooltip formatValue={valueFormatter} metric={metric} />
  )
  const isSkeleton = showChartSkeleton
  const renderChart =
    isPanelVisible && activeChartData.length > 0

  return (
    <div className={cn('flex h-full w-full min-w-0 flex-col', className)}>
      <div className={overviewChartPanelHeaderClass}>
        <div className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">
          {title ? (
            <span className="truncate text-[13px] font-medium text-foreground">
              {t(title)}
            </span>
          ) : null}
          {metric === 'gbhours' ? (
            <TooltipProvider delayDuration={0}>
              <GbHoursUnitInfo />
            </TooltipProvider>
          ) : null}
        </div>
        <div className={overviewChartPanelHeaderActionsClass}>
          {showBandwidthDualSeries && (
            <>
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
            </>
          )}
          {showViewAllLink && projectId ? (
            <OverviewViewAllUsageLink projectId={projectId} tabId={metric} />
          ) : null}
        </div>
      </div>

      <div className={overviewChartPanelBodyClass}>
        {isError ? (
          <div className={overviewChartPanelChartAreaClass}>
            <OverviewChartPanelError
              title={t(errorTitle)}
              message={
                typeof errorMessage === 'string' ? t(errorMessage) : errorMessage
              }
              onRetry={onRetry}
              upgradeOrgId={upgradeOrgId}
            />
          </div>
        ) : (
          <div
            className={cn(
              overviewChartPanelChartAreaClass,
              'text-muted-foreground',
              isSkeleton && 'pointer-events-none',
            )}
            aria-busy={isSkeleton}
            aria-label={isSkeleton ? t('Loading usage data') : undefined}
          >
            {showEmptyState ? (
              <div className={overviewChartPanelEmptyClass}>
                {t('No data for this date range')}
              </div>
            ) : null}
            {renderChart ? (
              <div className={overviewChartPanelChartFillClass}>
                <ResponsiveContainer
                  {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}
                  minHeight={OVERVIEW_CHART_HEIGHT}
                >
                <AreaChart
                  data={activeChartData}
                  margin={USAGE_CHART_MARGIN}
                >
                  <defs>
                    {isSkeleton ? (
                      <linearGradient
                        id={skeletonGradientId}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={SKELETON_CHART_FILL}
                          stopOpacity={SKELETON_CHART_FILL_TOP_OPACITY}
                        />
                        <stop
                          offset="100%"
                          stopColor={SKELETON_CHART_FILL}
                          stopOpacity={SKELETON_CHART_FILL_BOTTOM_OPACITY}
                        />
                      </linearGradient>
                    ) : showBandwidthDualSeries ? (
                      <>
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
                            stopOpacity={0.15}
                          />
                          <stop
                            offset="100%"
                            stopColor="var(--chart-brand)"
                            stopOpacity={0}
                          />
                        </linearGradient>
                      </>
                    ) : (
                      <linearGradient
                        id={areaGradientId}
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
                    )}
                  </defs>
                  <UsageChartXAxis
                    points={activeChartData}
                    dateRange={dateRange}
                    chartInterval={resolvedChartInterval}
                    variant="overview"
                  />
                  <UsageChartYAxis
                    tickFormatter={yAxisTickFormatter}
                    domain={bandwidthYAxisDomain}
                  />
                  {!isSkeleton ? (
                    <Tooltip content={tooltipContent} cursor={false} />
                  ) : null}
                  {isSkeleton ? (
                    <Area
                      type="monotone"
                      dataKey="total"
                      stroke={SKELETON_CHART_STROKE}
                      strokeWidth={2}
                      fill={`url(#${skeletonGradientId})`}
                      dot={false}
                      activeDot={false}
                      {...CHART_ANIMATION_DISABLED}
                    />
                  ) : showBandwidthDualSeries ? (
                    <>
                      <Area
                        type="monotone"
                        dataKey="inbound"
                        name="Inbound"
                        stackId="bandwidth"
                        stroke="var(--chart-2)"
                        strokeWidth={2}
                        fill={`url(#${inboundGradientId})`}
                        dot={false}
                        activeDot={{
                          r: 4,
                          fill: 'var(--chart-2)',
                          stroke: '#fff',
                          strokeWidth: 2,
                        }}
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
                        dot={false}
                        activeDot={{
                          r: 4,
                          fill: 'var(--chart-brand)',
                          stroke: '#fff',
                          strokeWidth: 2,
                        }}
                        {...CHART_ANIMATION_DISABLED}
                      />
                    </>
                  ) : (
                    <Area
                      type="monotone"
                      dataKey="total"
                      stroke="var(--chart-brand)"
                      strokeWidth={2}
                      fill={`url(#${areaGradientId})`}
                      dot={false}
                      activeDot={{
                        r: 4,
                        fill: 'var(--chart-brand)',
                        stroke: '#fff',
                        strokeWidth: 2,
                      }}
                      {...CHART_ANIMATION_DISABLED}
                    />
                  )}
                </AreaChart>
              </ResponsiveContainer>
              </div>
            ) : null}
          </div>
        )}
      </div>
    </div>
  )
})
