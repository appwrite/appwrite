import { cn } from '@/lib/utils'
import { useMemo, useState } from 'react'
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

interface RequestsChartProps {
  className?: string
  variant?: 'line' | 'bar'
  title?: string
  metric?: MetricType
  /** When omitted, the chart shows the last 30 days of the generated series */
  dateRange?: DateRange
}

const CHART_HISTORY_DAYS = 366

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
  const toBound = dateRange.to ? endOfDay(dateRange.to) : endOfDay(dateRange.from)

  return data.filter((d) => isWithinInterval(d.day, { start: from, end: toBound }))
}

interface CustomTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    dataKey: string
    payload: ChartPoint
  }>
  label?: string
}

const CustomTooltip = ({ active, payload }: CustomTooltipProps) => {
  if (active && payload && payload.length) {
    const data = payload[0].payload
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
}: RequestsChartProps) {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const { projectId } = useParams({ strict: false })

  const chartData = useMemo(
    () => filterChartDataByRange(FULL_CHART_DATA, dateRange),
    [dateRange],
  )

  const { successRate, errorRate } = useMemo(() => {
    const totalSuccessful = chartData.reduce((sum, d) => sum + d.successful, 0)
    const totalErrors = chartData.reduce((sum, d) => sum + d.errors, 0)
    const totalRequests = totalSuccessful + totalErrors
    if (totalRequests === 0) {
      return { successRate: 0, errorRate: 0 }
    }
    const sr = Math.round((totalSuccessful / totalRequests) * 100)
    return { successRate: sr, errorRate: 100 - sr }
  }, [chartData])

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

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Chart header with latency and legend */}
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
      <div className="flex-1 text-muted-foreground">
        {chartData.length === 0 ? (
          <div className="flex h-[240px] items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 text-[13px] text-muted-foreground">
            No data for this date range
          </div>
        ) : variant === 'line' ? (
          <ResponsiveContainer width="100%" height={240}>
            <AreaChart
              data={chartData}
              margin={{ top: 10, right: 10, left: 0, bottom: 0 }}
            >
              <defs>
                <linearGradient
                  id="successGradient"
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
                tickFormatter={(value) => {
                  if (value >= 1000) return `${(value / 1000).toFixed(0)}k`
                  return value.toString()
                }}
                dx={-5}
                width={40}
              />
              <Tooltip content={<CustomTooltip />} cursor={false} />
              <Area
                type="monotone"
                dataKey="total"
                stroke="var(--chart-brand)"
                strokeWidth={2}
                fill="url(#successGradient)"
                dot={false}
                activeDot={{
                  r: 4,
                  fill: 'var(--chart-brand)',
                  stroke: '#fff',
                  strokeWidth: 2,
                }}
              />
            </AreaChart>
          </ResponsiveContainer>
        ) : (
          <ResponsiveContainer width="100%" height={240}>
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
                tickFormatter={(value) => {
                  if (value >= 1000) return `${(value / 1000).toFixed(0)}k`
                  return value.toString()
                }}
                dx={-5}
                width={40}
              />
              <Tooltip content={<CustomTooltip />} cursor={false} />
              <Bar
                dataKey="successful"
                stackId="requests"
                fill="#10b981"
                radius={[0, 0, 0, 0]}
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
        )}
      </div>
    </div>
  )
}
