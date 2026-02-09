import { cn } from '@/lib/utils'
import { useState } from 'react'
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

type MetricType =
  | 'bandwidth'
  | 'requests'
  | 'storage'
  | 'executions'
  | 'gbhours'

interface RequestsChartProps {
  className?: string
  variant?: 'line' | 'bar'
  title?: string
  metric?: MetricType
}

// Generate mock data for the requests chart
const generateChartData = () => {
  const data = []
  const startDate = new Date('2024-09-26')

  for (let i = 0; i < 30; i++) {
    const date = new Date(startDate)
    date.setDate(startDate.getDate() + i)
    const dateStr = date.toLocaleDateString('en-US', {
      day: 'numeric',
      month: 'short',
    })

    // Generate realistic looking data with some variation
    const baseRequests = 5000 + Math.random() * 4000
    const errorRate = 0.08 + Math.random() * 0.15
    const successful = Math.floor(baseRequests * (1 - errorRate))
    const errors = Math.floor(baseRequests * errorRate)

    data.push({
      date: dateStr,
      successful,
      errors,
      total: successful + errors,
    })
  }
  return data
}

const chartData = generateChartData()

// Calculate totals for the legend
const totalSuccessful = chartData.reduce((sum, d) => sum + d.successful, 0)
const totalErrors = chartData.reduce((sum, d) => sum + d.errors, 0)
const totalRequests = totalSuccessful + totalErrors
const successRate = Math.round((totalSuccessful / totalRequests) * 100)
const errorRate = 100 - successRate

interface CustomTooltipProps {
  active?: boolean
  payload?: Array<{
    value: number
    dataKey: string
    payload: {
      date: string
      successful: number
      errors: number
      total: number
    }
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
}: RequestsChartProps) {
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const { projectId } = useParams({ strict: false })

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
        {variant === 'line' ? (
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
