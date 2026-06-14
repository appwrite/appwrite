'use client'

import { useMemo, useState, type ReactNode } from 'react'
import { format, subDays } from 'date-fns'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  XAxis,
  YAxis,
} from 'recharts'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  formatCompactBytes,
  formatCompactBytesAxis,
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import { cn } from '@/lib/utils'

type MetricId = 'requests' | 'bandwidth' | 'builds' | 'compute'

type MetricConfig = {
  id: MetricId
  label: string
  /** Aggregate for the 30-day chart window (sum of daily points). */
  periodTotal: number
  formatTotal: (value: number) => string
  formatAxis: (value: number) => string
  bars: readonly number[]
  breakdownTitle: string
  breakdown: readonly { label: string; value: string }[]
}

const METRICS: MetricConfig[] = [
  {
    id: 'requests',
    label: 'Requests',
    periodTotal: 124_000,
    formatTotal: (value) => formatCompactCount(value, { compact: true }),
    formatAxis: formatCompactCountAxis,
    bars: [42, 58, 51, 64, 59, 72, 68],
    breakdownTitle: 'Top paths',
    breakdown: [
      { label: '/', value: '42%' },
      { label: '/pricing', value: '18%' },
    ],
  },
  {
    id: 'bandwidth',
    label: 'Bandwidth',
    periodTotal: 18 * 1_000_000_000,
    formatTotal: (value) => formatCompactBytes(value, { compact: true }),
    formatAxis: formatCompactBytesAxis,
    bars: [36, 44, 40, 52, 48, 55, 50],
    breakdownTitle: 'By asset type',
    breakdown: [
      { label: 'Static assets', value: '61%' },
      { label: 'SSR responses', value: '24%' },
    ],
  },
  {
    id: 'builds',
    label: 'Builds',
    periodTotal: 42,
    formatTotal: (value) => Math.round(value).toString(),
    formatAxis: (value) => Math.round(value).toString(),
    bars: [28, 34, 31, 38, 35, 40, 36],
    breakdownTitle: 'By trigger',
    breakdown: [
      { label: 'Git push', value: '74%' },
      { label: 'Manual', value: '14%' },
    ],
  },
  {
    id: 'compute',
    label: 'Compute',
    periodTotal: 6.2,
    formatTotal: (value) => `${value.toFixed(1)} GB-h`,
    formatAxis: (value) => value.toFixed(1),
    bars: [32, 38, 35, 41, 39, 44, 42],
    breakdownTitle: 'By region',
    breakdown: [
      { label: 'US East', value: '38%' },
      { label: 'EU West', value: '31%' },
    ],
  },
]

const CHART_COLOR = 'var(--chart-brand)'

const CHART_MARGIN = { top: 8, right: 8, left: 4, bottom: 8 } as const
const CHART_DAYS = 30

function interpolateBarWeight(bars: readonly number[], index: number, dayCount: number) {
  const position = (index / Math.max(dayCount - 1, 1)) * (bars.length - 1)
  const sourceIndex = Math.floor(position)
  const nextIndex = Math.min(sourceIndex + 1, bars.length - 1)
  const blend = position - sourceIndex
  const raw = bars[sourceIndex] * (1 - blend) + bars[nextIndex] * blend
  const jitter = Math.sin(index * 0.85) * 0.04 * raw
  return Math.max(0.01, raw + jitter)
}

function buildChartSeries(bars: readonly number[], periodTotal: number) {
  const today = new Date()
  const weights = Array.from({ length: CHART_DAYS }, (_, index) =>
    interpolateBarWeight(bars, index, CHART_DAYS),
  )
  const weightSum = weights.reduce((sum, weight) => sum + weight, 0)

  const values = weights.map((weight) => (weight / weightSum) * periodTotal)

  // Keep the displayed total exact after rounding (counts / builds).
  const rounded = values.map((value) =>
    periodTotal >= 100 ? Math.round(value) : Math.round(value * 10) / 10,
  )
  const roundedSum = rounded.reduce((sum, value) => sum + value, 0)
  rounded[rounded.length - 1] += periodTotal - roundedSum

  return rounded.map((value, index) => ({
    date: format(subDays(today, CHART_DAYS - 1 - index), 'MMM d'),
    value,
  }))
}

function TrafficUsageChart({
  bars,
  periodTotal,
  formatAxis,
  gradientId,
}: {
  bars: readonly number[]
  periodTotal: number
  formatAxis: (value: number) => string
  gradientId: string
}) {
  const chartData = useMemo(
    () => buildChartSeries(bars, periodTotal),
    [bars, periodTotal],
  )

  return (
    <div className="h-[132px] w-full min-w-0 text-muted-foreground">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={chartData} margin={{ ...CHART_MARGIN }}>
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={CHART_COLOR} stopOpacity={0.2} />
              <stop offset="100%" stopColor={CHART_COLOR} stopOpacity={0} />
            </linearGradient>
          </defs>
          <CartesianGrid
            strokeDasharray="3 3"
            stroke="hsl(var(--border))"
            vertical={false}
          />
          <XAxis
            dataKey="date"
            axisLine={false}
            tickLine={false}
            tick={{ fill: 'currentColor', fontSize: 10 }}
            dy={10}
            interval="preserveStartEnd"
            minTickGap={24}
          />
          <YAxis
            axisLine={false}
            tickLine={false}
            tick={{ fill: 'currentColor', fontSize: 10 }}
            tickFormatter={formatAxis}
            dx={-5}
            width={40}
          />
          <Area
            type="monotone"
            dataKey="value"
            stroke={CHART_COLOR}
            strokeWidth={2}
            fill={`url(#${gradientId})`}
            dot={false}
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

const SITE_LOGS = [
  { id: '1', status: 200, path: '/pricing', duration: '124ms', selected: true },
  { id: '2', status: 200, path: '/', duration: '89ms', selected: false },
  { id: '3', status: 404, path: '/old-blog/post', duration: '12ms', selected: false },
  { id: '4', status: 500, path: '/dashboard', duration: '1.2s', selected: false },
] as const

const RESPONSE_LOG = `GET /pricing 200 · Rendered in 118ms
[console] Loaded 12 products from catalog`

function statusBadgeVariant(status: number) {
  if (status >= 500) return 'error' as const
  if (status >= 400) return 'warning' as const
  return 'success' as const
}

function UsagePanel() {
  const [activeMetric, setActiveMetric] = useState<MetricId>('requests')
  const metric = useMemo(
    () => METRICS.find((item) => item.id === activeMetric) ?? METRICS[0],
    [activeMetric],
  )

  return (
    <div className="flex min-h-0 flex-col">
      <div className="border-b border-border px-3 py-1.5 sm:px-4">
        <div className="flex gap-1 overflow-x-auto">
          {METRICS.map((item) => {
            const isActive = item.id === activeMetric
            return (
              <button
                key={item.id}
                type="button"
                onClick={() => setActiveMetric(item.id)}
                className={cn(
                  'shrink-0 rounded-md border px-2 py-1 text-left transition-colors',
                  isActive
                    ? 'border-border bg-muted/40'
                    : 'border-transparent bg-transparent hover:bg-muted/20',
                )}
              >
                <p className="text-[10px] font-medium text-muted-foreground">{item.label}</p>
                <p className="text-[12px] font-semibold leading-tight text-foreground">
                  {item.formatTotal(item.periodTotal)}
                </p>
              </button>
            )
          })}
        </div>
      </div>

      <div className="min-w-0 px-3 pb-3 pt-2 sm:px-4">
        <TrafficUsageChart
          bars={metric.bars}
          periodTotal={metric.periodTotal}
          formatAxis={metric.formatAxis}
          gradientId={`sites-traffic-${metric.id}`}
        />

        <div className="mt-3 border-t border-border pt-3">
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {metric.breakdownTitle}
          </p>
          <div className="space-y-1.5">
            {metric.breakdown.map((row) => (
              <div key={row.label} className="flex items-center gap-2 text-[10px]">
                <span className="w-16 shrink-0 truncate font-mono text-foreground">{row.label}</span>
                <div className="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-muted/50">
                  <div
                    className="h-full rounded-full bg-[var(--chart-brand)]/70 transition-[width] duration-300"
                    style={{ width: row.value }}
                  />
                </div>
                <span className="w-7 shrink-0 text-right text-muted-foreground">{row.value}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

function LogsPanel() {
  const selected = SITE_LOGS.find((log) => log.selected) ?? SITE_LOGS[0]

  return (
    <div className="flex min-h-0 flex-col">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              Status
            </TableHead>
            <TableHead className="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              Path
            </TableHead>
            <TableHead className="px-3 py-1.5 pr-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              Time
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {SITE_LOGS.map((log) => (
            <TableRow
              key={log.id}
              className={cn(
                'border-b border-border',
                log.selected ? 'bg-muted/60 hover:bg-muted/60' : 'hover:bg-muted/40',
              )}
            >
              <TableCell className="px-3 py-1.5">
                <Badge variant={statusBadgeVariant(log.status)} className="text-[10px]">
                  {log.status}
                </Badge>
              </TableCell>
              <TableCell className="max-w-[7rem] truncate px-3 py-1.5 font-mono text-[10px] text-foreground">
                {log.path}
              </TableCell>
              <TableCell className="px-3 py-1.5 pr-3 text-[10px] text-muted-foreground">
                {log.duration}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      <div className="border-t border-border bg-muted/10 p-3">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          SSR output
        </p>
        <pre className="mt-1.5 whitespace-pre-wrap font-mono text-[10px] leading-relaxed text-foreground">
          {RESPONSE_LOG.replace('/pricing', selected.path)}
        </pre>
      </div>
    </div>
  )
}

function SectionLabel({ children }: { children: ReactNode }) {
  return (
    <div className="border-b border-border bg-muted/10 px-3 py-2">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {children}
      </p>
    </div>
  )
}

export function SitesObservabilityVisual() {
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/45">
      <div className="grid lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div className="min-w-0 border-b border-border lg:border-b-0 lg:border-r">
          <SectionLabel>Traffic</SectionLabel>
          <UsagePanel />
        </div>
        <div className="min-w-0">
          <SectionLabel>Request logs</SectionLabel>
          <LogsPanel />
        </div>
      </div>
    </div>
  )
}
