import { useCallback, useMemo } from 'react'
import { format } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  YAxis,
} from 'recharts'
import { Info } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { POSTGRES_USAGE_PLACEHOLDER_NOTE } from '@/lib/postgres-usage-placeholder-metrics'
import {
  Tooltip as UITooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { useT } from '@/lib/i18n/translate'

export type PostgresMetricSeriesPoint = {
  timestamp: number
  value: number
  secondaryValue?: number
}

type PostgresMetricChartProps = {
  id: string
  title: string
  description: string
  unit?: string
  secondaryUnit?: string
  /** Legend/tooltip label for the primary series (defaults to title). */
  primaryLabel?: string
  secondaryLabel?: string
  data: PostgresMetricSeriesPoint[]
  formatY?: (value: number) => string
  formatSecondaryY?: (value: number) => string
  emptyMessage?: string
  /** When set with usageQuota or usageUnitLabel, shows a value in the chart header. */
  usageValue?: number | null
  /** Peer headline value shown at equal weight beside usageValue (e.g. write IOPS). */
  usageSecondaryValue?: number | null
  usageQuota?: number | null
  usageQuotaLabel?: string
  /** Shown next to the value when there is no meaningful quota (e.g. percent gauges). */
  usageUnitLabel?: string
  /** Label for usageSecondaryValue when dual headlines are shown. */
  usageSecondaryUnitLabel?: string
  /** Renders sample-data styling until usage service metrics are wired up. */
  isPlaceholder?: boolean
  placeholderNote?: string
  /** Keep chart/header height stable while date range or interval refetch. */
  isLoading?: boolean
  className?: string
}

const CHART_COLOR = 'var(--chart-brand)'
const SECONDARY_CHART_COLOR = 'var(--chart-2)'
const CHART_HEIGHT_PX = 180
const METRIC_HEADER_MIN_CLASS =
  'mt-2 min-h-[52px] flex flex-wrap items-baseline gap-x-2 gap-y-1'

const AREA_MARGIN = {
  top: 8,
  right: 8,
  left: 4,
  bottom: 8,
} as const

const Y_TICK_CHAR_PX = 6.25
const Y_TICK_PAD_PX = 10
const Y_AXIS_WIDTH_MIN = 30
const Y_AXIS_WIDTH_MAX = 52

function formatYAxisTickCompact(
  value: number,
  formatY: (v: number) => string,
): string {
  const labeled = formatY(value)
  if (labeled.includes('%')) return labeled
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(0)}M`
  if (value >= 1_000) return `${(value / 1_000).toFixed(0)}K`
  if (labeled.includes('.') || !Number.isInteger(value)) return labeled
  return Math.round(value).toString()
}

function collectYAxisTickSamples(
  values: number[],
  formatY: (v: number) => string,
): number[] {
  const out = new Set<number>()
  if (values.length === 0) {
    out.add(0)
    return [...out]
  }
  const sorted = [...values].sort((a, b) => a - b)
  const lo = sorted[0]!
  const hi = sorted[sorted.length - 1]!
  const span = hi - lo || 1
  out.add(lo)
  out.add(hi)
  out.add(lo + span / 2)
  for (let i = 0; i <= 4; i++) out.add(lo + (span * i) / 4)
  if (lo <= 0 && hi >= 0) out.add(0)
  const midSample = sorted[Math.floor(sorted.length / 2)]!
  if (formatY(midSample).includes('%')) {
    for (const t of [0, 20, 40, 60, 80, 100]) {
      if (t >= lo - 1 && t <= hi + 1) out.add(t)
    }
  }
  return [...out]
}

function measureYAxisWidth(
  samples: number[],
  formatTick: (v: number) => string,
): number {
  let maxChars = 0
  for (const v of samples) {
    maxChars = Math.max(maxChars, formatTick(v).length)
  }
  const w = Math.ceil(maxChars * Y_TICK_CHAR_PX + Y_TICK_PAD_PX)
  return Math.min(Y_AXIS_WIDTH_MAX, Math.max(Y_AXIS_WIDTH_MIN, w))
}

function ChartBodySkeleton() {
  return (
    <div
      className="relative w-full shrink-0"
      style={{ height: CHART_HEIGHT_PX }}
    >
      <Skeleton className="absolute inset-0 rounded-md" />
    </div>
  )
}

export function PostgresMetricChart({
  id,
  title,
  description,
  unit,
  secondaryUnit,
  primaryLabel,
  secondaryLabel,
  data,
  formatY = (v) => v.toFixed(1),
  formatSecondaryY,
  emptyMessage = 'Collecting samples. Keep this view open to build a time series from live SQL metrics.',
  usageValue,
  usageSecondaryValue,
  usageQuota,
  usageQuotaLabel = 'available',
  usageUnitLabel,
  usageSecondaryUnitLabel,
  isPlaceholder = false,
  placeholderNote = POSTGRES_USAGE_PLACEHOLDER_NOTE,
  isLoading = false,
  className,
}: PostgresMetricChartProps) {
  const t = useT()
  const gradientId = `postgres-metric-gradient-${id}`
  const secondaryGradientId = `postgres-metric-gradient-secondary-${id}`
  const seriesPrimaryLabel = primaryLabel ?? title
  const hasSecondary = data.some((point) => point.secondaryValue != null)
  const secondaryFormatter = formatSecondaryY ?? formatY
  const hasUsageQuota = usageQuota != null && usageQuota > 0
  // Percent gauges already imply 0–100; do not show "/ 100% capacity · 42%".
  const isTrivialPercentQuota =
    hasUsageQuota &&
    usageQuota === 100 &&
    (formatY(0).includes('%') ||
      (usageValue != null && formatY(usageValue).includes('%')))
  const reserveUsageHeader =
    hasUsageQuota ||
    !!usageUnitLabel ||
    usageSecondaryValue != null ||
    !!usageSecondaryUnitLabel
  const showUsageQuotaSummary = hasUsageQuota && !isTrivialPercentQuota

  const chartData = useMemo(
    () =>
      data.map((point) => ({
        date: formatLocalizedDate(new Date(point.timestamp), 'MMM d'),
        time: format(new Date(point.timestamp), 'HH:mm'),
        fullDate: formatLocalizedDate(new Date(point.timestamp), 'MMM d, yyyy HH:mm'),
        value: point.value,
        secondaryValue: point.secondaryValue,
      })),
    [data],
  )
  const xAxisLabels = useMemo(
    () => chartData.map((point) => point.time),
    [chartData],
  )

  const yAxisTickFormatter = useCallback(
    (v: number) => formatYAxisTickCompact(v, formatY),
    [formatY],
  )

  const yAxisWidth = useMemo(() => {
    const vals = data.flatMap((d) => [
      d.value,
      ...(d.secondaryValue != null ? [d.secondaryValue] : []),
      ...(usageQuota != null && usageQuota > 0 ? [usageQuota] : []),
    ])
    const samples = collectYAxisTickSamples(vals, formatY)
    return measureYAxisWidth(samples, (v) => formatYAxisTickCompact(v, formatY))
  }, [data, formatY, usageQuota])

  const yAxisDomain = useMemo(() => {
    if (usageQuota == null || usageQuota <= 0) {
      return undefined
    }
    return [
      0,
      (max: number) => Math.max(max, usageQuota),
    ] as [number, (max: number) => number]
  }, [usageQuota])

  const showUsageValue =
    usageValue != null && Number.isFinite(usageValue)
  const showUsageSecondaryValue =
    usageSecondaryValue != null && Number.isFinite(usageSecondaryValue)
  const showDualUsageHeadline = showUsageValue && showUsageSecondaryValue

  const usagePercent =
    showUsageValue && showUsageQuotaSummary && usageQuota != null
      ? (usageValue / usageQuota) * 100
      : null

  const showEmpty = !isLoading && data.length < 2

  return (
    <div
      id={`postgres-metric-chart-${id}`}
      className={cn(
        'scroll-mt-[calc(4rem+env(safe-area-inset-top))] w-full overflow-hidden rounded-lg border border-border bg-card',
        isPlaceholder && 'border-dashed',
        className,
      )}
    >
      <div className="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[14px] font-medium text-foreground">{title}</h3>
            {isPlaceholder ? (
              <Badge variant="info" className="text-[10px] shrink-0">
                {t('Sample data')}
              </Badge>
            ) : null}
            <TooltipProvider delayDuration={0}>
              <UITooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    className="text-muted-foreground transition-colors hover:text-foreground"
                  >
                    <Info className="h-3.5 w-3.5" />
                  </button>
                </TooltipTrigger>
                <TooltipContent
                  side="top"
                  className="max-w-xs text-[12px] leading-relaxed"
                >
                  <p>{description}</p>
                </TooltipContent>
              </UITooltip>
            </TooltipProvider>
          </div>
          {reserveUsageHeader ? (
            <div className={METRIC_HEADER_MIN_CLASS}>
              {isLoading ? (
                <>
                  <Skeleton className="h-7 w-28 shrink-0 rounded-sm" />
                  <Skeleton className="h-4 w-[4.5rem] shrink-0 rounded-sm" />
                  {usageSecondaryUnitLabel || usageSecondaryValue != null ? (
                    <>
                      <Skeleton className="h-7 w-28 shrink-0 rounded-sm" />
                      <Skeleton className="h-4 w-[4.5rem] shrink-0 rounded-sm" />
                    </>
                  ) : null}
                </>
              ) : showDualUsageHeadline ? (
                <>
                  <span className="inline-flex items-baseline gap-2">
                    <span className="text-[24px] font-semibold tabular-nums text-foreground">
                      {formatY(usageValue)}
                    </span>
                    {usageUnitLabel ? (
                      <span className="text-[13px] text-muted-foreground">
                        {t(usageUnitLabel)}
                      </span>
                    ) : null}
                  </span>
                  <span className="text-[13px] text-muted-foreground" aria-hidden>
                    ·
                  </span>
                  <span className="inline-flex items-baseline gap-2">
                    <span className="text-[24px] font-semibold tabular-nums text-foreground">
                      {secondaryFormatter(usageSecondaryValue)}
                    </span>
                    {usageSecondaryUnitLabel ? (
                      <span className="text-[13px] text-muted-foreground">
                        {t(usageSecondaryUnitLabel)}
                      </span>
                    ) : null}
                  </span>
                </>
              ) : showUsageValue ? (
                <>
                  <span className="text-[24px] font-semibold tabular-nums text-foreground">
                    {formatY(usageValue)}
                  </span>
                  {showUsageQuotaSummary ? (
                    <span className="text-[13px] text-muted-foreground">
                      / {formatY(usageQuota!)} {t(usageQuotaLabel)}
                      {usagePercent != null
                        ? ` · ${usagePercent.toFixed(1)}%`
                        : null}
                    </span>
                  ) : usageUnitLabel ? (
                    <span className="text-[13px] text-muted-foreground">
                      {t(usageUnitLabel)}
                    </span>
                  ) : null}
                </>
              ) : null}
            </div>
          ) : null}
          {secondaryLabel ? (
            <div className="mt-2 flex min-h-[20px] flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
              {hasSecondary || isLoading ? (
                <>
                  <span className="inline-flex items-center gap-1.5">
                    <span
                      className="h-2 w-2 rounded-full"
                      style={{ backgroundColor: CHART_COLOR }}
                    />
                    {t(seriesPrimaryLabel)}
                  </span>
                  <span className="inline-flex items-center gap-1.5">
                    <span
                      className="h-2 w-2 rounded-full"
                      style={{ backgroundColor: SECONDARY_CHART_COLOR }}
                    />
                    {t(secondaryLabel)}
                  </span>
                </>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>

      <div className="p-4">
        {isLoading ? (
          <ChartBodySkeleton />
        ) : showEmpty ? (
          <div
            className="flex items-center justify-center rounded-md border border-dashed border-border bg-muted/20 px-6 text-center"
            style={{ height: CHART_HEIGHT_PX }}
          >
            <p className="max-w-sm text-[12px] leading-relaxed text-muted-foreground">
              {t(emptyMessage)}
            </p>
          </div>
        ) : (
          <div
            className="shrink-0 text-muted-foreground"
            style={{ height: CHART_HEIGHT_PX }}
          >
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData} margin={{ ...AREA_MARGIN }}>
                <defs>
                  <linearGradient
                    id={gradientId}
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="1"
                  >
                    <stop
                      offset="0%"
                      stopColor={CHART_COLOR}
                      stopOpacity={0.2}
                    />
                    <stop
                      offset="100%"
                      stopColor={CHART_COLOR}
                      stopOpacity={0}
                    />
                  </linearGradient>
                  {hasSecondary ? (
                    <linearGradient
                      id={secondaryGradientId}
                      x1="0"
                      y1="0"
                      x2="0"
                      y2="1"
                    >
                      <stop
                        offset="0%"
                        stopColor={SECONDARY_CHART_COLOR}
                        stopOpacity={0.15}
                      />
                      <stop
                        offset="100%"
                        stopColor={SECONDARY_CHART_COLOR}
                        stopOpacity={0}
                      />
                    </linearGradient>
                  ) : null}
                </defs>
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="hsl(var(--border))"
                  vertical={false}
                />
                <SeriesChartXAxis
                  pointCount={chartData.length}
                  labels={xAxisLabels}
                  tick={{
                    fill: 'currentColor',
                    fontSize: 10,
                    className: 'tabular-nums',
                  }}
                  dy={8}
                />
                <YAxis
                  axisLine={false}
                  tickLine={false}
                  tick={{
                    fill: 'currentColor',
                    fontSize: 10,
                    className: 'tabular-nums',
                  }}
                  tickFormatter={yAxisTickFormatter}
                  dx={0}
                  width={yAxisWidth}
                  domain={yAxisDomain}
                />
                {showUsageQuotaSummary ? (
                  <ReferenceLine
                    y={usageQuota!}
                    stroke="hsl(var(--muted-foreground) / 0.45)"
                    strokeDasharray="4 4"
                    ifOverflow="extendDomain"
                  />
                ) : null}
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const row = payload[0].payload as {
                      fullDate: string
                      value: number
                      secondaryValue?: number
                    }
                    return (
                      <div className="rounded-md border border-border bg-popover px-3 py-2">
                        <p className="mb-1 text-[11px] text-muted-foreground">
                          {row.fullDate}
                        </p>
                        {hasSecondary && secondaryLabel ? (
                          <div className="space-y-1">
                            <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                              <ChartSeriesDot color={CHART_COLOR} />
                              {t(seriesPrimaryLabel)}:{' '}
                              {unit
                                ? `${formatY(row.value)} ${unit}`
                                : formatY(row.value)}
                            </p>
                            {row.secondaryValue != null ? (
                              <p className="flex items-center gap-1.5 text-[13px] font-medium text-foreground">
                                <ChartSeriesDot color={SECONDARY_CHART_COLOR} />
                                {t(secondaryLabel)}:{' '}
                                {secondaryUnit
                                  ? `${secondaryFormatter(row.secondaryValue)} ${secondaryUnit}`
                                  : secondaryFormatter(row.secondaryValue)}
                              </p>
                            ) : null}
                          </div>
                        ) : (
                          <p className="text-[13px] font-medium text-foreground">
                            {unit
                              ? `${formatY(row.value)} ${unit}`
                              : formatY(row.value)}
                            {showUsageQuotaSummary &&
                            usageQuota != null &&
                            !formatY(row.value).includes('%') ? (
                              <span className="font-normal text-muted-foreground">
                                {' '}
                                ·{' '}
                                {((row.value / usageQuota) * 100).toFixed(1)}%
                              </span>
                            ) : null}
                          </p>
                        )}
                      </div>
                    )
                  }}
                />
                <Area
                  type="monotone"
                  dataKey="value"
                  stroke={CHART_COLOR}
                  strokeWidth={2}
                  fill={`url(#${gradientId})`}
                  name={seriesPrimaryLabel}
                  isAnimationActive={false}
                />
                {hasSecondary ? (
                  <Area
                    type="monotone"
                    dataKey="secondaryValue"
                    stroke={SECONDARY_CHART_COLOR}
                    strokeWidth={2}
                    fill={`url(#${secondaryGradientId})`}
                    name={secondaryLabel ?? 'Secondary'}
                    isAnimationActive={false}
                  />
                ) : null}
              </AreaChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {isPlaceholder ? placeholderNote : description}
        </p>
      </div>
    </div>
  )
}
