import { useMemo, useCallback } from 'react'
import { useParams } from '@tanstack/react-router'
import { differenceInCalendarDays, endOfDay, format, startOfDay } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  YAxis,
} from 'recharts'
import { DatabaseType as ApiDatabaseType } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { useProjectDatabase } from '@/lib/react-query/hooks'
import {
  getEffectiveDatabaseSpecIdForMonitoring,
  isServerlessDatabaseMonitoring,
} from '@/lib/database-specs'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'

type MonitorSection = { id: string; label: string }

function dateRangeToSeriesParams(range: DateRange | undefined): {
  days: number
  endMs: number
} {
  if (!range?.from) {
    return { days: 30, endMs: Date.now() }
  }
  const to = endOfDay(range.to ?? range.from)
  const from = startOfDay(range.from)
  const days = Math.max(1, differenceInCalendarDays(to, from) + 1)
  return { days, endMs: to.getTime() }
}

function buildMockSeries(
  days: number,
  phase: number,
  base: number,
  amplitude: number,
  endMs: number,
  /** Bumps the wave phase when the user refreshes (mock data only). */
  refreshEpoch = 0,
) {
  return Array.from({ length: days }, (_, i) => {
    const t = i / Math.max(days - 1, 1)
    const wave =
      Math.sin(phase + refreshEpoch * 0.01 + t * Math.PI * 2) * amplitude
    const jitter =
      Math.sin(phase * 1.7 + refreshEpoch * 0.02 + i * 0.9) * amplitude * 0.15
    return {
      timestamp: endMs - (days - 1 - i) * 86_400_000,
      value: Math.max(0, base + wave + jitter),
    }
  })
}

type MonitorChartProps = {
  id: string
  title: string
  description: string
  unit: string
  data: { timestamp: number; value: number }[]
  formatY?: (v: number) => string
}

/** Same as `/projects/.../usage` UsageMetricChart (stroke + fill). */
const USAGE_CHART_COLOR = 'var(--chart-brand)'

/**
 * Margins stay ≥ 0 so ticks are not drawn under `overflow-hidden` parents.
 * Tight right/top; bottom leaves room for X tick labels (dy).
 */
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

/**
 * Compact Y-axis labels (tooltip still uses full `formatY` from caller).
 * Avoids wide `toLocaleString()` on the axis for sub-1K values.
 */
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

function MonitorChart({
  id,
  title,
  description,
  unit,
  data,
  formatY = (v) => v.toFixed(1),
}: MonitorChartProps) {
  const gradientId = `monitor-gradient-${id}`
  const chartData = useMemo(
    () =>
      data.map((point) => ({
        date: format(new Date(point.timestamp), 'MMM d'),
        fullDate: format(new Date(point.timestamp), 'MMM d, yyyy'),
        value: point.value,
      })),
    [data],
  )

  const yAxisTickFormatter = useCallback(
    (v: number) => formatYAxisTickCompact(v, formatY),
    [formatY],
  )

  const yAxisWidth = useMemo(() => {
    const vals = data.map((d) => d.value)
    const samples = collectYAxisTickSamples(vals, formatY)
    return measureYAxisWidth(samples, (v) => formatYAxisTickCompact(v, formatY))
  }, [data, formatY])

  return (
    <div
      id={`monitor-chart-${id}`}
      className="scroll-mt-[calc(4rem+env(safe-area-inset-top))] w-full overflow-hidden rounded-lg border border-border bg-card"
    >
      {/* Match UsageMetricChart header: title row + bottom border */}
      <div className="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <h3 className="text-[14px] font-medium text-foreground">{title}</h3>
        </div>
      </div>

      {/* Match UsageMetricChart chart block: p-4, h-[180px], same AreaChart margins */}
      <div className="p-4">
        <div className="h-[180px] text-muted-foreground">
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
                    stopColor={USAGE_CHART_COLOR}
                    stopOpacity={0.2}
                  />
                  <stop
                    offset="100%"
                    stopColor={USAGE_CHART_COLOR}
                    stopOpacity={0}
                  />
                </linearGradient>
              </defs>
              <CartesianGrid
                strokeDasharray="3 3"
                stroke="hsl(var(--border))"
                vertical={false}
              />
              <SeriesChartXAxis
                pointCount={chartData.length}
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
              />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const row = payload[0].payload as {
                    fullDate: string
                    value: number
                  }
                  return (
                    <div className="rounded-md border border-border bg-popover px-3 py-2">
                      <p className="text-[11px] text-muted-foreground mb-1">
                        {row.fullDate}
                      </p>
                      <p className="text-[13px] font-medium text-foreground">
                        {unit
                          ? `${formatY(row.value)} ${unit}`
                          : formatY(row.value)}
                      </p>
                    </div>
                  )
                }}
              />
              <Area
                type="monotone"
                dataKey="value"
                stroke={USAGE_CHART_COLOR}
                strokeWidth={2}
                fill={`url(#${gradientId})`}
                name={title}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Match UsageMetricChart description footer */}
      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
    </div>
  )
}

function MonitorSidebarNav({
  sections,
  onNavigate,
  className,
}: {
  sections: MonitorSection[]
  onNavigate: (id: string) => void
  className?: string
}) {
  return (
    <nav
      className={cn('space-y-0.5', className)}
      role="navigation"
      aria-label="Monitor metrics"
    >
      <p className="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        Metrics
      </p>
      {sections.map((s) => (
        <button
          key={s.id}
          type="button"
          onClick={() => onNavigate(s.id)}
          className={cn(
            'flex w-full items-center rounded-md px-2 py-1.5 text-start text-[13px] transition-colors',
            'text-muted-foreground hover:bg-accent hover:text-foreground',
          )}
        >
          <span className="truncate">{s.label}</span>
        </button>
      ))}
    </nav>
  )
}

export type DatabaseMonitorViewProps = {
  databaseId: string
  dateRange: DateRange
  chartTick: number
}

export function DatabaseMonitorView({
  databaseId,
  dateRange,
  chartTick,
}: DatabaseMonitorViewProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string

  const { database } = useProjectDatabase(projectId, databaseId)
  const databaseType =
    (database as { databaseType?: ApiDatabaseType } | null)?.databaseType ??
    ApiDatabaseType.Tablesdb

  const specId = getEffectiveDatabaseSpecIdForMonitoring(databaseType)
  const serverless = isServerlessDatabaseMonitoring(databaseType, specId)

  const { days, endMs } = dateRangeToSeriesParams(dateRange)

  const readSeries = useMemo(
    () => buildMockSeries(days, 0.3, 12_000, 4_000, endMs, chartTick),
    [days, endMs, chartTick],
  )
  const writeSeries = useMemo(
    () => buildMockSeries(days, 1.1, 8_000, 2_500, endMs, chartTick),
    [days, endMs, chartTick],
  )

  const cpuSeries = useMemo(
    () => buildMockSeries(days, 0.5, 42, 18, endMs, chartTick),
    [days, endMs, chartTick],
  )
  const ramSeries = useMemo(
    () => buildMockSeries(days, 0.9, 58, 15, endMs, chartTick),
    [days, endMs, chartTick],
  )
  const diskSeries = useMemo(
    () => buildMockSeries(days, 1.4, 35, 12, endMs, chartTick),
    [days, endMs, chartTick],
  )
  const netSeries = useMemo(
    () => buildMockSeries(days, 2.0, 24, 10, endMs, chartTick),
    [days, endMs, chartTick],
  )
  const connSeries = useMemo(
    () => buildMockSeries(days, 0.2, 48, 22, endMs, chartTick),
    [days, endMs, chartTick],
  )

  const sections: MonitorSection[] = serverless
    ? [
        { id: 'reads', label: 'Read operations' },
        { id: 'writes', label: 'Write operations' },
      ]
    : [
        { id: 'cpu', label: 'CPU usage' },
        { id: 'memory', label: 'Memory usage' },
        { id: 'disk', label: 'Disk usage' },
        { id: 'network', label: 'Network throughput' },
        { id: 'connections', label: 'Connections' },
      ]

  const scrollToChart = useCallback((id: string) => {
    const el = document.getElementById(`monitor-chart-${id}`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [])

  return (
    <div className="flex h-full min-h-0 w-full overflow-hidden">
      <aside className="hidden w-[200px] shrink-0 border-e border-border lg:block">
        <div className="h-full overflow-y-auto p-3">
          <MonitorSidebarNav sections={sections} onNavigate={scrollToChart} />
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <div className="min-h-0 flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            {serverless ? (
              <>
                <MonitorChart
                  id="reads"
                  title="Read operations"
                  description="Total database read operations per day."
                  unit="reads"
                  data={readSeries}
                  formatY={(v) => Math.round(v).toLocaleString()}
                />
                <MonitorChart
                  id="writes"
                  title="Write operations"
                  description="Total database write operations per day."
                  unit="writes"
                  data={writeSeries}
                  formatY={(v) => Math.round(v).toLocaleString()}
                />
              </>
            ) : (
              <>
                <MonitorChart
                  id="cpu"
                  title="CPU usage"
                  description="Average CPU utilization for this database instance."
                  unit=""
                  data={cpuSeries}
                  formatY={(v) => `${Math.round(v)}%`}
                />
                <MonitorChart
                  id="memory"
                  title="Memory usage"
                  description="Memory utilization relative to provisioned RAM."
                  unit=""
                  data={ramSeries}
                  formatY={(v) => `${Math.round(v)}%`}
                />
                <MonitorChart
                  id="disk"
                  title="Disk usage"
                  description="Disk utilization for database storage."
                  unit=""
                  data={diskSeries}
                  formatY={(v) => `${Math.round(v)}%`}
                />
                <MonitorChart
                  id="network"
                  title="Network throughput"
                  description="Combined ingress and egress."
                  unit="MB/s"
                  data={netSeries}
                  formatY={(v) => `${v.toFixed(1)}`}
                />
                <MonitorChart
                  id="connections"
                  title="Connections"
                  description="Active client connections."
                  unit="connections"
                  data={connSeries}
                  formatY={(v) => `${Math.round(v)}`}
                />
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
