import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
} from 'recharts'
import { useT } from '@/lib/i18n/translate'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { cn } from '@/lib/utils'
import {
  defaultConsoleChartInterval,
  consoleMetricToChartPoints,
  consoleMetricsToBarRows,
  resolveConsoleChartAxisFormat,
  resolveConsoleChartType,
  resolveConsoleChartUnitLabel,
  sumConsoleChartPoints,
  type ConsoleChartAction,
} from '@/lib/assistant/console-chart'
import { resolveConsoleChartHref } from '@/lib/assistant/console-protocol'
import {
  buildConsoleUrl,
} from '@/lib/utils/context-menu'
import {
  CHART_ANIMATION_DISABLED,
} from '@/lib/usage/chart-animation'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import {
  createUsageChartAxisTickFormatter,
  formatCompactBytes,
  formatCompactCount,
  formatGbHoursTotal,
  formatGbHoursValue,
  getChartSeriesMax,
  type UsageChartAxisFormat,
} from '@/lib/usage/format-metric'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import type { UsageChartPoint } from '@/lib/usage/usage-events-common'

const AGENT_CHART_HEIGHT = 200

function formatByAxis(
  value: number,
  axisFormat: UsageChartAxisFormat,
): string {
  if (axisFormat === 'bytes') return formatCompactBytes(value)
  if (axisFormat === 'gbhours') return formatGbHoursValue(value)
  return formatCompactCount(value)
}

function formatTotalByAxis(
  value: number,
  axisFormat: UsageChartAxisFormat,
): string {
  if (axisFormat === 'bytes') return formatCompactBytes(value)
  if (axisFormat === 'gbhours') return formatGbHoursTotal(value)
  return formatCompactCount(value)
}

function ConsoleChartTooltipRow({
  label,
  value,
}: {
  label: string
  value: string
}) {
  return (
    <div className="rounded-lg border border-border bg-popover px-3 py-2.5 shadow-sm">
      <p className="text-[11px] text-muted-foreground">{label}</p>
      <p className="mt-0.5 text-[13px] font-medium tabular-nums text-foreground">
        {value}
      </p>
    </div>
  )
}

export function ConsoleChartView({
  action,
  projectId,
  openInNewTab = false,
}: {
  action: ConsoleChartAction & { key: string }
  projectId?: string | null
  openInNewTab?: boolean
}) {
  const t = useT()
  const chartType = resolveConsoleChartType(action)
  const unitLabel = resolveConsoleChartUnitLabel(action)
  const axisFormat = resolveConsoleChartAxisFormat(action)
  const chartInterval = defaultConsoleChartInterval(action.interval)
  const href = resolveConsoleChartHref(
    action.href,
    action.projectId ?? projectId,
  )
  const gradientId = `agent-console-chart-${action.key.replace(/[^a-zA-Z0-9_-]/g, '-')}`

  const chartPoints = useMemo(() => {
    const metrics = Array.isArray(action.metrics) ? action.metrics : []
    if (metrics.length === 0) return [] as UsageChartPoint[]
    // Primary series: first metric (agent should emit one chart action per series
    // when multiple metrics need separate visuals).
    return consoleMetricToChartPoints(metrics[0]!, {
      interval: action.interval,
      startAt: action.startAt,
      endAt: action.endAt,
      kind: action.kind,
    })
  }, [action.endAt, action.interval, action.kind, action.metrics, action.startAt])

  const barRows = useMemo(() => {
    if (chartType !== 'bar') return []
    return consoleMetricsToBarRows(
      Array.isArray(action.metrics) ? action.metrics : [],
    )
  }, [action.metrics, chartType])

  const areaData = useMemo(
    () =>
      chartPoints.map((point) => ({
        date: point.date,
        fullDate: formatLocalizedDate(point.day, 'MMM d, yyyy HH:mm'),
        value: point.total,
      })),
    [chartPoints],
  )

  const total =
    chartType === 'bar'
      ? barRows.reduce((sum, row) => sum + row.value, 0)
      : sumConsoleChartPoints(chartPoints)

  const dateRange = useMemo(() => {
    if (chartPoints.length === 0) return undefined
    return {
      from: chartPoints[0]!.day,
      to: chartPoints[chartPoints.length - 1]!.day,
    }
  }, [chartPoints])

  const chartAxisMax = useMemo(() => {
    if (chartType === 'bar') {
      return Math.max(0, ...barRows.map((row) => row.value))
    }
    return getChartSeriesMax(areaData)
  }, [areaData, barRows, chartType])

  const yAxisTickFormatter = useMemo(
    () => createUsageChartAxisTickFormatter(axisFormat, chartAxisMax),
    [axisFormat, chartAxisMax],
  )

  const formattedTotal = formatTotalByAxis(total, axisFormat)
  const hasData =
    chartType === 'bar' ? barRows.length > 0 : chartPoints.length > 0
  const showChange =
    typeof action.changePercent === 'number' && Number.isFinite(action.changePercent)
  const changePercent = action.changePercent ?? 0
  const changeLabel =
    changePercent > 0
      ? `+${changePercent}%`
      : changePercent < 0
        ? `${changePercent}%`
        : '0%'

  const chartColor = 'var(--chart-brand)'

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <div className="flex flex-col gap-1 border-b border-border px-4 py-3">
        <div className="flex min-w-0 items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <h3 className="text-[14px] font-medium text-foreground">
              {t(action.title)}
            </h3>
            {action.description ? (
              <p className="mt-1 text-[12px] text-muted-foreground">
                {t(action.description)}
              </p>
            ) : null}
            <div className="mt-2 flex min-h-[28px] flex-wrap items-baseline gap-x-2 gap-y-1">
              <span className="text-[22px] font-semibold tabular-nums text-foreground">
                {formattedTotal}
              </span>
              <span className="text-[13px] text-muted-foreground">
                {t(unitLabel)}
              </span>
              {showChange ? (
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
              ) : null}
            </div>
          </div>
          {href ? (
            openInNewTab ? (
              <a
                href={buildConsoleUrl(href)}
                target="_blank"
                rel="noopener noreferrer"
                className="shrink-0 text-[12px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
              >
                {t('View usage')}
              </a>
            ) : (
              <Link
                to={href as never}
                className="shrink-0 text-[12px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
              >
                {t('View usage')}
              </Link>
            )
          ) : null}
        </div>
      </div>

      <div className="p-4">
        <div
          className={cn(
            'relative w-full shrink-0 text-muted-foreground',
            FORCE_LTR_CLASS,
          )}
          style={{ height: AGENT_CHART_HEIGHT }}
        >
          {!hasData ? (
            <div className="absolute inset-0 flex items-center justify-center text-[13px] text-muted-foreground">
              {t('No chart data')}
            </div>
          ) : chartType === 'bar' ? (
            <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
              <BarChart data={barRows} margin={USAGE_CHART_MARGIN}>
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="hsl(var(--border))"
                  vertical={false}
                />
                <XAxis
                  dataKey="label"
                  tick={{ fill: 'currentColor', fontSize: 10 }}
                  tickLine={false}
                  axisLine={false}
                  interval="preserveStartEnd"
                  minTickGap={16}
                />
                <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
                <Tooltip
                  isAnimationActive={false}
                  cursor={{ fill: 'hsl(var(--muted) / 0.35)' }}
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const row = payload[0]?.payload as {
                      fullLabel: string
                      value: number
                    }
                    return (
                      <ConsoleChartTooltipRow
                        label={row.fullLabel}
                        value={formatByAxis(row.value, axisFormat)}
                      />
                    )
                  }}
                />
                <Bar
                  dataKey="value"
                  fill={chartColor}
                  radius={[4, 4, 0, 0]}
                  {...CHART_ANIMATION_DISABLED}
                />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
              <AreaChart data={areaData} margin={USAGE_CHART_MARGIN}>
                <defs>
                  <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
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
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const row = payload[0]?.payload as {
                      fullDate: string
                      value: number
                    }
                    return (
                      <ConsoleChartTooltipRow
                        label={row.fullDate}
                        value={formatByAxis(row.value, axisFormat)}
                      />
                    )
                  }}
                />
                <Area
                  type="monotone"
                  dataKey="value"
                  stroke={chartColor}
                  strokeWidth={2}
                  fill={`url(#${gradientId})`}
                  {...CHART_ANIMATION_DISABLED}
                />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </div>
      </div>
    </div>
  )
}
