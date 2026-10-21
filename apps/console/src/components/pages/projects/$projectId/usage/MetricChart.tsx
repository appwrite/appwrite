import { useMemo } from 'react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  createCompactCountAxisTickFormatter,
  getChartSeriesMax,
} from '@/lib/usage/format-metric'
import { OVERVIEW_CHART_HEIGHT } from '../overview/chart-panel'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import { SeriesChartXAxis, UsageChartYAxis } from '@/components/global/shared/ChartXAxis'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  CartesianGrid,
} from 'recharts'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { AlertTriangle } from 'lucide-react'
import {
  type UsageMetric,
  formatMetricValue,
  getUsagePercentage,
  getUsageStatus,
} from './data'
import { Progress } from '@/components/ui/progress'

interface UsageMetricChartProps {
  metric: UsageMetric
  className?: string
}

export function UsageMetricChart({ metric, className }: UsageMetricChartProps) {
  const t = useT()
  // Transform time series data for recharts
  const chartData = useMemo(() => {
    return metric.timeSeries.map((point) => ({
      date: formatLocalizedDate(new Date(point.timestamp), 'MMM d'),
      fullDate: formatLocalizedDate(new Date(point.timestamp), 'MMM d, yyyy'),
      value: point.value,
    }))
  }, [metric.timeSeries])

  const chartAxisMax = useMemo(() => getChartSeriesMax(chartData), [chartData])
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  // Calculate usage percentage and status
  const usagePercentage = getUsagePercentage(metric.currentValue, metric.quota)
  const usageStatus = getUsageStatus(usagePercentage)

  // Determine chart color based on status
  const chartColor = useMemo(() => {
    switch (usageStatus) {
      case 'critical':
        return '#ef4444' // red-500
      case 'warning':
        return '#f59e0b' // amber-500
      default:
        return 'var(--chart-brand)'
    }
  }, [usageStatus])

  // Format the current value for display
  const formattedValue = formatMetricValue(metric.currentValue, metric.unit)
  const formattedQuota = metric.quota
    ? formatMetricValue(metric.quota, metric.unit)
    : t('Unlimited')

  return (
    <div
      className={cn(
        'rounded-lg border border-border bg-card overflow-hidden',
        className,
      )}
    >
      {/* Header */}
      <div className="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex-1 min-w-0">
          <h3 className="text-[14px] font-medium text-foreground">
            {t(metric.name)}
          </h3>

          {/* Current value and quota */}
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-[24px] font-semibold text-foreground tabular-nums">
              {formattedValue}
            </span>
            {metric.quota !== null && (
              <span className="text-[13px] text-muted-foreground">
                / {formattedQuota}
              </span>
            )}
          </div>

          {/* Progress bar for quota */}
          {usagePercentage !== null && (
            <div className="mt-3 space-y-1.5">
              <Progress
                value={usagePercentage}
                className={cn(
                  'h-2',
                  usageStatus === 'critical' && '[&>div]:bg-red-500',
                  usageStatus === 'warning' && '[&>div]:bg-amber-500',
                  usageStatus === 'normal' && '[&>div]:bg-primary',
                )}
              />
              <div className="flex items-center justify-between text-[11px]">
                <span className="text-muted-foreground">
                  {usagePercentage.toFixed(1)}% {t('used')}
                </span>
                {usageStatus !== 'normal' && (
                  <span
                    className={cn(
                      'flex items-center gap-1 font-medium',
                      usageStatus === 'critical' && 'text-red-500',
                      usageStatus === 'warning' && 'text-amber-500',
                    )}
                  >
                    <AlertTriangle className="h-3 w-3" />
                    {usageStatus === 'critical'
                      ? t('Approaching limit')
                      : t('High usage')}
                  </span>
                )}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Chart */}
      <div className="p-4">
        <div
          className={cn('text-muted-foreground', FORCE_LTR_CLASS)}
          style={{ height: OVERVIEW_CHART_HEIGHT }}
        >
          <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
            <AreaChart
              data={chartData}
              margin={USAGE_CHART_MARGIN}
            >
              <defs>
                <linearGradient
                  id={`gradient-${metric.id}`}
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop offset="0%" stopColor={chartColor} stopOpacity={0.2} />
                  <stop offset="100%" stopColor={chartColor} stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid
                strokeDasharray="3 3"
                stroke="hsl(var(--border))"
                vertical={false}
              />
              <SeriesChartXAxis pointCount={chartData.length} />
              <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload || !payload.length) return null
                  const data = payload[0].payload
                  return (
                    <div className="rounded-md border border-border bg-popover px-3 py-2">
                      <p className="text-[11px] text-muted-foreground mb-1">
                        {data.fullDate}
                      </p>
                      <p className="text-[13px] font-medium text-foreground">
                        {formatMetricValue(data.value, metric.unit)}{' '}
                        <span className="text-muted-foreground font-normal">
                          {t(metric.unit)}
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
                fill={`url(#gradient-${metric.id})`}
                name={metric.name}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Description */}
      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {t(metric.description)}
        </p>
      </div>
    </div>
  )
}
