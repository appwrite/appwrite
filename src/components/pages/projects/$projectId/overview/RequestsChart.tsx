import { useLayoutEffect, useMemo, useRef, useState } from 'react'
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Link, useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { ArrowRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  OVERVIEW_CHART_HEIGHT,
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
import { TooltipProvider } from '@/components/ui/tooltip'
import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCountAxis,
  formatGbHoursAxisValue,
} from '@/lib/usage/format-metric'
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

type MetricType =
  | 'bandwidth'
  | 'requests'
  | 'storage'
  | 'executions'
  | 'gbhours'

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
  errorTitle?: string
  errorMessage?: string
  formatValue?: (value: number) => string
  /** Bumps when the parent tab becomes visible so enter animation runs on first show. */
  showSession?: number
  /** When false, the chart stays unmounted until the panel is shown. */
  isPanelVisible?: boolean
  /** Hide the link to the full usage page (e.g. when already on /usage). */
  showViewAllLink?: boolean
}

const CHART_ANIMATION_DURATION = 800

/** Soft greyscale placeholder — visible on the real chart canvas. */
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

/** Remount key for chart enter animation — tied to visibility context, not fetched data. */
function buildChartShowKey(
  metric: MetricType,
  dateRange: DateRange | undefined,
  showSession?: number,
) {
  const from = dateRange?.from?.toISOString() ?? 'default-from'
  const to = dateRange?.to?.toISOString() ?? 'default-to'
  return `${metric}-line-${from}-${to}-${showSession ?? 0}`
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
              <span className="text-[11px] text-muted-foreground">Inbound</span>
              <span className="text-[12px] font-medium text-foreground">
                <FormattedMetricValue value={format(data.inbound)} />
              </span>
            </div>
            <div className="flex items-center justify-between gap-6">
              <span className="text-[11px] text-muted-foreground">Outbound</span>
              <span className="text-[12px] font-medium text-foreground">
                <FormattedMetricValue value={format(data.outbound)} />
              </span>
            </div>
            <div className="flex items-center justify-between gap-6 border-t border-border pt-1.5">
              <span className="text-[11px] text-muted-foreground">Total</span>
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
            {metric === 'bandwidth' ? 'Bandwidth' : 'Value'}
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

export function RequestsChart({
  className,
  title,
  metric = 'requests',
  dateRange,
  chartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  chartData: chartDataProp = [],
  isLoading = false,
  isError = false,
  onRetry,
  errorTitle = OVERVIEW_BANDWIDTH_ERROR.title,
  errorMessage = OVERVIEW_BANDWIDTH_ERROR.message,
  formatValue,
  showSession = 0,
  isPanelVisible = true,
  showViewAllLink = true,
}: RequestsChartProps) {
  const [chartMountKey, setChartMountKey] = useState<string | null>(null)
  const { projectId } = useParams({ strict: false })

  const chartData = chartDataProp
  const skeletonChartData = useMemo(
    () => buildSkeletonChartData(dateRange, chartInterval, metric),
    [dateRange, chartInterval, metric],
  )
  const showBandwidthDualSeries =
    !isLoading &&
    metric === 'bandwidth' &&
    chartData.some(
      (point) =>
        typeof point.inbound === 'number' && typeof point.outbound === 'number',
    )

  const chartShowKey = useMemo(
    () => buildChartShowKey(metric, dateRange, showSession),
    [metric, dateRange?.from, dateRange?.to, showSession],
  )
  const showChartSkeleton = isLoading && chartData.length === 0
  const showEmptyState = !isLoading && chartData.length === 0
  const showChart = chartMountKey === chartShowKey
  const showChartSkeletonRef = useRef(showChartSkeleton)
  showChartSkeletonRef.current = showChartSkeleton
  const chartDataLengthRef = useRef(chartData.length)
  chartDataLengthRef.current = chartData.length
  const areaGradientId = `overview-chart-gradient-${metric}`
  const skeletonGradientId = `overview-chart-gradient-skeleton-${metric}`
  const inboundGradientId = 'overview-chart-gradient-inbound'
  const outboundGradientId = 'overview-chart-gradient-outbound'
  const valueFormatter =
    formatValue ??
    (metric === 'requests' || metric === 'executions'
      ? (value: number) => String(value)
      : metric === 'gbhours'
        ? (value: number) => String(value)
        : (value: number) => formatCompactBytes(value, { compact: true }))
  const yAxisTickFormatter =
    metric === 'requests' || metric === 'executions'
      ? (value: number) => formatCompactCountAxis(value)
      : metric === 'gbhours'
        ? (value: number) => formatGbHoursAxisValue(value)
        : (value: number) => formatCompactBytesAxis(value)
  const tooltipContent = (
    <CustomTooltip formatValue={valueFormatter} metric={metric} />
  )
  const activeChartData = showChartSkeleton ? skeletonChartData : chartData
  const isSkeleton = showChartSkeleton
  const renderChart =
    isPanelVisible &&
    activeChartData.length > 0 &&
    (showChartSkeleton || showChart)

  useLayoutEffect(() => {
    if (
      isError ||
      !isPanelVisible ||
      showChartSkeleton ||
      chartData.length === 0
    ) {
      setChartMountKey(null)
      return
    }

    let cancelled = false
    let mountTimerId = 0
    let mountRafId = 0

    mountTimerId = window.setTimeout(() => {
      mountRafId = requestAnimationFrame(() => {
        if (
          cancelled ||
          showChartSkeletonRef.current ||
          chartDataLengthRef.current === 0
        ) {
          return
        }

        setChartMountKey(chartShowKey)
      })
    }, 0)

    return () => {
      cancelled = true
      clearTimeout(mountTimerId)
      cancelAnimationFrame(mountRafId)
    }
  }, [chartShowKey, isError, isPanelVisible, showChartSkeleton, chartData.length])

  const areaAnimationProps = isSkeleton
    ? { isAnimationActive: false }
    : {
        isAnimationActive: true,
        animationDuration: CHART_ANIMATION_DURATION,
        animationEasing: 'ease-out' as const,
        animationBegin: 0,
      }

  return (
    <div className={cn('flex h-full w-full min-w-0 flex-col', className)}>
      <div className={overviewChartPanelHeaderClass}>
        <div className="flex min-w-0 items-center gap-1.5">
          {title ? (
            <span className="text-[13px] font-medium text-foreground">
              {title}
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
                  Inbound
                </span>
              </div>
              <div className="flex items-center gap-1.5">
                <div
                  className="h-2 w-2 rounded-full"
                  style={{ backgroundColor: 'var(--chart-brand)' }}
                />
                <span className="text-[11px] text-muted-foreground">
                  Outbound
                </span>
              </div>
            </>
          )}
          {showViewAllLink && projectId && (
            <Link
              to="/projects/$projectId/usage/$categoryId"
              params={{
                projectId,
                categoryId:
                  metric === 'bandwidth' ? 'bandwidth' : 'requests',
              }}
            >
              <Button
                variant="ghost"
                size="sm"
                className="h-7 gap-1.5 text-[11px] text-muted-foreground hover:text-foreground"
              >
                View all usage
                <ArrowRight className="h-3 w-3" />
              </Button>
            </Link>
          )}
        </div>
      </div>

      <div className={overviewChartPanelBodyClass}>
        {isError ? (
          <OverviewChartPanelError
            title={errorTitle}
            message={errorMessage}
            onRetry={onRetry}
          />
        ) : (
          <div
            className={cn(
              overviewChartPanelChartAreaClass,
              'text-muted-foreground',
              isSkeleton && 'pointer-events-none',
            )}
            aria-busy={isSkeleton}
            aria-label={isSkeleton ? 'Loading usage data' : undefined}
          >
            {showEmptyState ? (
              <div className={overviewChartPanelEmptyClass}>
                No data for this date range
              </div>
            ) : null}
            {renderChart ? (
              <div className={overviewChartPanelChartFillClass}>
                <ResponsiveContainer
                  key={isSkeleton ? 'skeleton' : chartMountKey}
                  width="100%"
                  height="100%"
                  minHeight={OVERVIEW_CHART_HEIGHT}
                >
                <AreaChart
                  data={activeChartData}
                  margin={{ top: 10, right: 0, left: 0, bottom: 0 }}
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
                  <XAxis
                    dataKey="date"
                    axisLine={false}
                    tickLine={false}
                    tick={{
                      fill: 'currentColor',
                      fontSize: 10,
                    }}
                    dy={10}
                    interval="preserveStartEnd"
                    tickFormatter={(value, index) => {
                      if (index % 5 === 0) return value
                      return ''
                    }}
                  />
                  <YAxis
                    axisLine={false}
                    tickLine={false}
                    tick={{
                      fill: 'currentColor',
                      fontSize: 10,
                    }}
                    tickFormatter={yAxisTickFormatter}
                    dx={-5}
                    width={48}
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
                      {...areaAnimationProps}
                    />
                  ) : showBandwidthDualSeries ? (
                    <>
                      <Area
                        type="monotone"
                        dataKey="inbound"
                        name="Inbound"
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
                        {...areaAnimationProps}
                      />
                      <Area
                        type="monotone"
                        dataKey="outbound"
                        name="Outbound"
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
                        {...areaAnimationProps}
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
                      {...areaAnimationProps}
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
}
