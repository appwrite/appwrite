import { cn } from '@/lib/utils'
import {
  OVERVIEW_BANDWIDTH_ERROR,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewChartPanelEmptyClass,
  overviewChartPanelHeaderClass,
} from './chart-panel'
import { OverviewChartPanelError } from './OverviewChartPanelError'
import { OverviewChartPanelSkeleton } from './OverviewChartPanelSkeleton'
import { useLayoutEffect, useMemo, useRef, useState } from 'react'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  Cell,
} from 'recharts'
import { Link, useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { ArrowRight } from 'lucide-react'
import { endOfDay, isWithinInterval, startOfDay, subDays } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'

type MetricType =
  | 'bandwidth'
  | 'requests'
  | 'storage'
  | 'executions'
  | 'gbhours'

interface ChartPoint {
  date: string
  day: Date
  successful: number
  errors: number
  total: number
}

type RealChartPoint = {
  date: string
  day: Date
  total: number
  inbound?: number
  outbound?: number
}

interface RequestsChartProps {
  className?: string
  variant?: 'line' | 'bar'
  title?: string
  metric?: MetricType
  /** When omitted, the chart shows the last 30 days of the generated series */
  dateRange?: DateRange
  /** Real usage data (e.g. bandwidth from usage.listEvents). When set, mock data is skipped. */
  chartData?: RealChartPoint[]
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
}

const CHART_HISTORY_DAYS = 366
const CHART_ANIMATION_DURATION = 800

/** Remount key for chart enter animation — tied to visibility context, not fetched data. */
function buildChartShowKey(
  metric: MetricType,
  variant: 'line' | 'bar',
  dateRange: DateRange | undefined,
  showSession?: number,
) {
  const from = dateRange?.from?.toISOString() ?? 'default-from'
  const to = dateRange?.to?.toISOString() ?? 'default-to'
  return `${metric}-${variant}-${from}-${to}-${showSession ?? 0}`
}

// Deterministic pseudo-random so the series is stable across navigations (same day index → same values)
function seededNoise(seed: number) {
  const x = Math.sin(seed * 12.9898) * 43758.5453
  return x - Math.floor(x)
}

function generateFullChartData(): ChartPoint[] {
  const data: ChartPoint[] = []
  const end = startOfDay(new Date())

  for (let i = CHART_HISTORY_DAYS - 1; i >= 0; i--) {
    const day = subDays(end, i)
    const dateStr = day.toLocaleDateString('en-US', {
      day: 'numeric',
      month: 'short',
    })
    const s = day.getTime()
    const baseRequests = 5000 + seededNoise(s) * 4000
    const errorRate = 0.08 + seededNoise(s + 1) * 0.15
    const successful = Math.floor(baseRequests * (1 - errorRate))
    const errors = Math.floor(baseRequests * errorRate)

    data.push({
      date: dateStr,
      day,
      successful,
      errors,
      total: successful + errors,
    })
  }
  return data
}

const FULL_CHART_DATA = generateFullChartData()

function filterChartDataByRange(
  data: ChartPoint[],
  dateRange: DateRange | undefined,
): ChartPoint[] {
  const to = endOfDay(new Date())
  const fromDefault = startOfDay(subDays(to, 29))

  if (!dateRange?.from) {
    return data.filter((d) =>
      isWithinInterval(d.day, { start: fromDefault, end: to }),
    )
  }

  const from = startOfDay(dateRange.from)
  const toBound = dateRange.to
    ? endOfDay(dateRange.to)
    : endOfDay(dateRange.from)

  return data.filter((d) =>
    isWithinInterval(d.day, { start: from, end: toBound }),
  )
}

interface CustomTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    dataKey: string
    payload: ChartPoint | RealChartPoint
  }>
  label?: string
  formatValue?: (value: number) => string
  showBreakdown?: boolean
  metric?: MetricType
}

const CustomTooltip = ({
  active,
  payload,
  formatValue,
  showBreakdown = true,
  metric = 'requests',
}: CustomTooltipProps) => {
  if (active && payload && payload.length) {
    const data = payload[0].payload
    const format = formatValue ?? ((value: number) => value.toLocaleString())

    if (
      metric === 'bandwidth' &&
      'inbound' in data &&
      'outbound' in data &&
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
                {format(data.inbound)}
              </span>
            </div>
            <div className="flex items-center justify-between gap-6">
              <span className="text-[11px] text-muted-foreground">Outbound</span>
              <span className="text-[12px] font-medium text-foreground">
                {format(data.outbound)}
              </span>
            </div>
            <div className="flex items-center justify-between gap-6 border-t border-border pt-1.5">
              <span className="text-[11px] text-muted-foreground">Total</span>
              <span className="text-[12px] font-medium text-foreground">
                {format(data.total)}
              </span>
            </div>
          </div>
        </div>
      )
    }

    const total = 'total' in data ? data.total : 0

    if (!showBreakdown || !('successful' in data)) {
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
              {format(total)}
            </span>
          </div>
        </div>
      )
    }

    const successPercent = Math.round((data.successful / data.total) * 100)
    const errorPercent = 100 - successPercent

    return (
      <div className="rounded-lg border border-border bg-popover px-3 py-2.5">
        <p className="mb-2 text-[12px] font-medium text-foreground">
          {data.date}
        </p>
        <div className="space-y-1.5">
          <div className="flex items-center justify-between gap-6">
            <span className="text-[11px] text-muted-foreground">Total</span>
            <span className="text-[12px] font-medium text-foreground">
              {data.total.toLocaleString()}
            </span>
          </div>
          <div className="flex items-center justify-between gap-6">
            <span className="text-[11px] text-muted-foreground">
              Successful
            </span>
            <span className="text-[12px] text-muted-foreground">
              {successPercent}% {data.successful.toLocaleString()}
            </span>
          </div>
          <div className="flex items-center justify-between gap-6">
            <span className="text-[11px] text-muted-foreground">Error</span>
            <span className="text-[12px] text-muted-foreground">
              {errorPercent}% {data.errors.toLocaleString()}
            </span>
          </div>
        </div>
        <p className="mt-2 text-[10px] text-muted-foreground/50">
          Click to view day
        </p>
      </div>
    )
  }
  return null
}

export function RequestsChart({
  className,
  variant = 'line',
  title,
  metric = 'requests',
  dateRange,
  chartData: chartDataProp,
  isLoading = false,
  isError = false,
  onRetry,
  errorTitle = OVERVIEW_BANDWIDTH_ERROR.title,
  errorMessage = OVERVIEW_BANDWIDTH_ERROR.message,
  formatValue,
  showSession = 0,
  isPanelVisible = true,
}: RequestsChartProps) {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const [chartMountKey, setChartMountKey] = useState<string | null>(null)
  const { projectId } = useParams({ strict: false })

  const usesRealData = chartDataProp !== undefined

  const mockChartData = useMemo(
    () => filterChartDataByRange(FULL_CHART_DATA, dateRange),
    [dateRange],
  )

  const chartData = usesRealData ? (chartDataProp ?? []) : mockChartData
  const showBandwidthDualSeries =
    usesRealData &&
    metric === 'bandwidth' &&
    chartData.some(
      (point) =>
        typeof point.inbound === 'number' && typeof point.outbound === 'number',
    )

  const { successRate, errorRate } = useMemo(() => {
    if (usesRealData) {
      return { successRate: 0, errorRate: 0 }
    }
    const totalSuccessful = mockChartData.reduce((sum, d) => sum + d.successful, 0)
    const totalErrors = mockChartData.reduce((sum, d) => sum + d.errors, 0)
    const totalRequests = totalSuccessful + totalErrors
    if (totalRequests === 0) {
      return { successRate: 0, errorRate: 0 }
    }
    const sr = Math.round((totalSuccessful / totalRequests) * 100)
    return { successRate: sr, errorRate: 100 - sr }
  }, [mockChartData, usesRealData])

  const getMetricLabel = () => {
    switch (metric) {
      case 'bandwidth':
        return { primary: 'Transfer rate', secondary: 'Avg. bandwidth' }
      case 'storage':
        return { primary: 'Usage trend', secondary: 'Avg. storage' }
      case 'executions':
        return { primary: 'Execution time', secondary: 'Avg. duration' }
      case 'gbhours':
        return { primary: 'Compute usage', secondary: 'Avg. GB-hours' }
      default:
        return { primary: '200ms', secondary: 'Avg. latency' }
    }
  }

  const labels = getMetricLabel()
  const effectiveVariant = usesRealData ? 'line' : variant
  const chartShowKey = useMemo(
    () => buildChartShowKey(metric, effectiveVariant, dateRange, showSession),
    [metric, effectiveVariant, dateRange?.from, dateRange?.to, showSession],
  )
  const showChartSkeleton = isLoading && chartData.length === 0
  const showEmptyState = !isLoading && chartData.length === 0
  const showChart = chartMountKey === chartShowKey
  const showChartSkeletonRef = useRef(showChartSkeleton)
  showChartSkeletonRef.current = showChartSkeleton
  const chartDataLengthRef = useRef(chartData.length)
  chartDataLengthRef.current = chartData.length
  const mountedForKeyRef = useRef<string | null>(null)
  const areaGradientId = `overview-chart-gradient-${metric}`
  const inboundGradientId = 'overview-chart-gradient-inbound'
  const outboundGradientId = 'overview-chart-gradient-outbound'
  const valueFormatter =
    formatValue ??
    (metric === 'requests'
      ? (value: number) => String(value)
      : (value: number) => formatCompactBytes(value, { compact: true }))
  const yAxisTickFormatter = usesRealData
    ? metric === 'requests'
      ? (value: number) => formatCompactCountAxis(value)
      : (value: number) => formatCompactBytesAxis(value)
    : (value: number) => {
        if (value >= 1000) return `${(value / 1000).toFixed(0)}k`
        return value.toString()
      }
  const tooltipContent = (
    <CustomTooltip
      formatValue={usesRealData ? valueFormatter : undefined}
      showBreakdown={!usesRealData}
      metric={metric}
    />
  )

  useLayoutEffect(() => {
    if (
      isError ||
      !isPanelVisible ||
      showChartSkeleton ||
      chartData.length === 0
    ) {
      mountedForKeyRef.current = null
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

        mountedForKeyRef.current = chartShowKey
        setChartMountKey(chartShowKey)
      })
    }, 0)

    return () => {
      cancelled = true
      clearTimeout(mountTimerId)
      cancelAnimationFrame(mountRafId)
    }
  }, [chartShowKey, isError, isPanelVisible, showChartSkeleton, chartData.length])

  const areaAnimationProps = {
    isAnimationActive: true,
    animationDuration: CHART_ANIMATION_DURATION,
    animationEasing: 'ease-out' as const,
    animationBegin: 0,
  }

  const barAnimationProps = {
    isAnimationActive: true,
    animationDuration: CHART_ANIMATION_DURATION,
    animationEasing: 'ease-out' as const,
    animationBegin: 0,
  }

  return (
    <div className={cn('flex w-full flex-col @[700px]:h-full', className)}>
      {/* Chart header with latency and legend */}
      <div className={overviewChartPanelHeaderClass}>
        <div className="flex items-center gap-2">
          {title ? (
            <span className="text-[13px] font-medium text-foreground">
              {title}
            </span>
          ) : (
            <>
              <span className="text-[13px] font-medium text-foreground">
                {labels.primary}
              </span>
              <span className="text-[12px] text-muted-foreground">
                {labels.secondary}
              </span>
            </>
          )}
        </div>
        <div className="flex items-center gap-4">
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
          {!usesRealData && (
            <>
              <div className="flex items-center gap-1.5">
                <div className="h-2 w-2 rounded-full bg-emerald-500" />
                <span className="text-[11px] text-muted-foreground">
                  Successful
                </span>
                <span className="text-[11px] font-medium text-muted-foreground">
                  {successRate}%
                </span>
              </div>
              <div className="flex items-center gap-1.5">
                <div className="h-2 w-2 rounded-full bg-muted-foreground/30" />
                <span className="text-[11px] text-muted-foreground">Error</span>
                <span className="text-[11px] font-medium text-muted-foreground">
                  {errorRate}%
                </span>
              </div>
            </>
          )}
          {projectId && (
            <Link
              to="/projects/$projectId/usage"
              params={{ projectId }}
              className="ml-2"
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

      {/* Chart */}
      <div className={overviewChartPanelBodyClass}>
        {isError ? (
          <OverviewChartPanelError
            title={errorTitle}
            message={errorMessage}
            onRetry={onRetry}
          />
        ) : (
          <div className={cn(overviewChartPanelChartAreaClass, 'relative')}>
            {showChartSkeleton ? (
              <OverviewChartPanelSkeleton
                variant="chart"
                embedded
                className="absolute inset-0 z-10 h-full"
              />
            ) : null}
            {showEmptyState ? (
              <div className={overviewChartPanelEmptyClass}>
                No data for this date range
              </div>
            ) : null}
            {showChart && effectiveVariant === 'line' ? (
              <ResponsiveContainer
                key={chartMountKey}
                width="100%"
                height="100%"
              >
                <AreaChart
                  data={chartData}
                  margin={{ top: 10, right: 10, left: 0, bottom: 0 }}
                >
                  <defs>
                    {showBandwidthDualSeries ? (
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
                width={usesRealData ? 48 : 40}
              />
              <Tooltip content={tooltipContent} cursor={false} />
                  {showBandwidthDualSeries ? (
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
            ) : null}
            {showChart && effectiveVariant === 'bar' ? (
              <ResponsiveContainer
                key={chartMountKey}
                width="100%"
                height="100%"
              >
                <BarChart
                  data={chartData}
                  margin={{ top: 0, right: 0, left: 0, bottom: 0 }}
                  barCategoryGap="15%"
              onMouseMove={(state) => {
                if (
                  state.activeTooltipIndex !== undefined &&
                  typeof state.activeTooltipIndex === 'number'
                ) {
                  setHoveredIndex(state.activeTooltipIndex)
                }
              }}
              onMouseLeave={() => setHoveredIndex(null)}
            >
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
                width={usesRealData ? 48 : 40}
              />
              <Tooltip content={tooltipContent} cursor={false} />
                  <Bar
                    dataKey="successful"
                    stackId="requests"
                    fill="#10b981"
                    radius={[0, 0, 0, 0]}
                    {...barAnimationProps}
                  >
                {chartData.map((_, index) => (
                  <Cell
                    key={`successful-${index}`}
                    fill={hoveredIndex === index ? '#34d399' : '#10b981'}
                    opacity={
                      hoveredIndex !== null && hoveredIndex !== index ? 0.5 : 1
                    }
                  />
                ))}
              </Bar>
                  <Bar
                    dataKey="errors"
                    stackId="requests"
                    fill="hsl(var(--muted-foreground) / 0.2)"
                    radius={[2, 2, 0, 0]}
                    {...barAnimationProps}
                  >
                {chartData.map((_, index) => (
                  <Cell
                    key={`errors-${index}`}
                    fill={
                      hoveredIndex === index
                        ? 'hsl(var(--muted-foreground) / 0.35)'
                        : 'hsl(var(--muted-foreground) / 0.2)'
                    }
                    opacity={
                      hoveredIndex !== null && hoveredIndex !== index ? 0.5 : 1
                    }
                  />
                ))}
              </Bar>
                </BarChart>
              </ResponsiveContainer>
            ) : null}
          </div>
        )}
      </div>
    </div>
  )
}
