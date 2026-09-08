'use client'

import { useMemo, useState, type ReactNode } from 'react'
import { subDays } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  YAxis,
} from 'recharts'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  createUsageChartAxisTickFormatter,
  formatCompactBytes,
  formatCompactCount,
  getChartSeriesMax,
  type UsageChartAxisFormat,
} from '@/lib/usage/format-metric'
import { USAGE_CHART_Y_AXIS_WIDTH } from '@/components/pages/projects/$projectId/overview/chart-panel'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type MetricId = 'requests' | 'bandwidth' | 'builds' | 'compute'

type MetricConfig = {
  id: MetricId
  label: string
  /** Aggregate for the 30-day chart window (sum of daily points). */
  periodTotal: number
  formatTotal: (value: number) => string
  axisFormat: UsageChartAxisFormat | 'plain'
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
    axisFormat: 'count',
    bars: [42, 58, 51, 64, 59, 72, 68],
    breakdownTitle: 'Top paths',
    breakdown: [
      { label: '/', value: '42%' },
      { label: '/pricing', value: '18%' },
      { label: '/docs', value: '11%' },
    ],
  },
  {
    id: 'bandwidth',
    label: 'Bandwidth',
    periodTotal: 18 * 1_000_000_000,
    formatTotal: (value) => formatCompactBytes(value, { compact: true }),
    axisFormat: 'bytes',
    bars: [36, 44, 40, 52, 48, 55, 50],
    breakdownTitle: 'By asset type',
    breakdown: [
      { label: 'Static assets', value: '61%' },
      { label: 'SSR responses', value: '24%' },
      { label: 'Images', value: '9%' },
    ],
  },
  {
    id: 'builds',
    label: 'Builds',
    periodTotal: 42,
    formatTotal: (value) => Math.round(value).toString(),
    axisFormat: 'plain',
    bars: [28, 34, 31, 38, 35, 40, 36],
    breakdownTitle: 'By trigger',
    breakdown: [
      { label: 'Git push', value: '74%' },
      { label: 'Manual', value: '14%' },
      { label: 'Rollback', value: '8%' },
    ],
  },
  {
    id: 'compute',
    label: 'Compute',
    periodTotal: 6.2,
    formatTotal: (value) => `${value.toFixed(1)} GB-h`,
    axisFormat: 'gbhours',
    bars: [32, 38, 35, 41, 39, 44, 42],
    breakdownTitle: 'By region',
    breakdown: [
      { label: 'US East', value: '38%' },
      { label: 'EU West', value: '31%' },
      { label: 'AP South', value: '19%' },
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
    date: formatLocalizedDate(subDays(today, CHART_DAYS - 1 - index), 'MMM d'),
    value,
  }))
}

function TrafficUsageChart({
  bars,
  periodTotal,
  axisFormat,
  gradientId,
}: {
  bars: readonly number[]
  periodTotal: number
  axisFormat: UsageChartAxisFormat | 'plain'
  gradientId: string
}) {
  const chartData = useMemo(
    () => buildChartSeries(bars, periodTotal),
    [bars, periodTotal],
  )
  const chartAxisMax = useMemo(() => getChartSeriesMax(chartData), [chartData])
  const yAxisTickFormatter = useMemo(() => {
    if (axisFormat === 'plain') {
      return (value: number) => Math.round(value).toString()
    }
    return createUsageChartAxisTickFormatter(axisFormat, chartAxisMax)
  }, [axisFormat, chartAxisMax])

  return (
    <div className="h-[210px] w-full min-w-0 text-muted-foreground">
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
          <SeriesChartXAxis pointCount={chartData.length} />
          <YAxis
            axisLine={false}
            tickLine={false}
            tick={{ fill: 'currentColor', fontSize: 10 }}
            tickFormatter={yAxisTickFormatter}
            dx={-5}
            width={USAGE_CHART_Y_AXIS_WIDTH}
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
  { id: '1', status: 200, method: 'GET', path: '/pricing', duration: '124ms', selected: true },
  { id: '2', status: 200, method: 'GET', path: '/', duration: '89ms', selected: false },
  { id: '3', status: 304, method: 'GET', path: '/assets/logo.svg', duration: '8ms', selected: false },
  { id: '4', status: 404, method: 'GET', path: '/old-blog/post', duration: '12ms', selected: false },
  { id: '5', status: 200, method: 'POST', path: '/api/contact', duration: '342ms', selected: false },
  { id: '6', status: 500, method: 'GET', path: '/dashboard', duration: '1.2s', selected: false },
  { id: '7', status: 200, method: 'GET', path: '/docs/quick-start', duration: '156ms', selected: false },
] as const

const LOG_DETAILS: Record<
  (typeof SITE_LOGS)[number]['id'],
  { region: string; output: string }
> = {
  '1': {
    region: 'US East',
    output: `GET /pricing 200 · Rendered in 118ms
[console] Loaded 12 products from catalog
[console] Cached pricing tiers for 5m`,
  },
  '2': {
    region: 'EU West',
    output: `GET / 200 · Rendered in 84ms
[console] Prefetched hero assets
[console] ISR cache hit`,
  },
  '3': {
    region: 'US East',
    output: `GET /assets/logo.svg 304 · Not modified
cache-control: public, max-age=31536000`,
  },
  '4': {
    region: 'AP South',
    output: `GET /old-blog/post 404 · Not found
[console] Redirect rule skipped (no match)`,
  },
  '5': {
    region: 'US East',
    output: `POST /api/contact 200 · Rendered in 338ms
[console] Validated form payload
[console] Queued notification email`,
  },
  '6': {
    region: 'EU West',
    output: `GET /dashboard 500 · Internal error
[console] TypeError: Cannot read properties of undefined
[console] at DashboardPage (page.tsx:42)`,
  },
  '7': {
    region: 'US East',
    output: `GET /docs/quick-start 200 · Rendered in 151ms
[console] Resolved MDX bundle
[console] Generated TOC with 8 headings`,
  },
}

function statusBadgeVariant(status: number) {
  if (status >= 500) return 'error' as const
  if (status >= 400) return 'warning' as const
  return 'success' as const
}

function UsagePanel() {
  const t = useT()
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
                  'shrink-0 rounded-md border px-2 py-1 text-start transition-colors',
                  isActive
                    ? 'border-border bg-muted/40'
                    : 'border-transparent bg-transparent hover:bg-muted/20',
                )}
              >
                <p className="text-[10px] font-medium text-muted-foreground">{t(item.label)}</p>
                <p className="text-[12px] font-semibold leading-tight text-foreground">
                  {item.formatTotal(item.periodTotal)}
                </p>
              </button>
            )
          })}
        </div>
      </div>

      <div className="min-w-0 px-3 pb-4 pt-2 sm:px-4">
        <div className="mb-2 flex items-center justify-between gap-2">
          <p className="text-[10px] text-muted-foreground">{t('Last 30 days')}</p>
        </div>
        <TrafficUsageChart
          bars={metric.bars}
          periodTotal={metric.periodTotal}
          axisFormat={metric.axisFormat}
          gradientId={`sites-traffic-${metric.id}`}
        />

        <div className="mt-3 border-t border-border pt-3">
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t(metric.breakdownTitle)}
          </p>
          <div className="space-y-2">
            {metric.breakdown.map((row) => (
              <div key={row.label} className="flex items-center gap-2 text-[10px]">
                <span className="w-20 shrink-0 truncate font-mono text-foreground">{t(row.label)}</span>
                <div className="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-muted/50">
                  <div
                    className="h-full rounded-full bg-[var(--chart-brand)]/70 transition-[width] duration-300"
                    style={{ width: row.value }}
                  />
                </div>
                <span className="w-7 shrink-0 text-end text-muted-foreground">{row.value}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

function LogsPanel() {
  const t = useT()
  const selected = SITE_LOGS.find((log) => log.selected) ?? SITE_LOGS[0]
  const details = LOG_DETAILS[selected.id]

  return (
    <div className="flex min-h-0 flex-col">
      <div className="max-h-[11.5rem] overflow-hidden [mask-image:linear-gradient(to_bottom,black_calc(100%-2rem),transparent)] [-webkit-mask-image:linear-gradient(to_bottom,black_calc(100%-2rem),transparent)]">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Status')}
              </TableHead>
              <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Method')}
              </TableHead>
              <TableHead className="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Path')}
              </TableHead>
              <TableHead className="px-3 py-2 pe-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Time')}
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
                <TableCell className="px-3 py-2">
                  <Badge variant={statusBadgeVariant(log.status)} className="text-[10px]">
                    {log.status}
                  </Badge>
                </TableCell>
                <TableCell className="px-3 py-2 font-mono text-[10px] text-foreground">
                  {log.method}
                </TableCell>
                <TableCell className="max-w-[6rem] truncate px-3 py-2 font-mono text-[10px] text-foreground">
                  {log.path}
                </TableCell>
                <TableCell className="px-3 py-2 pe-3 text-[10px] text-muted-foreground">
                  {log.duration}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <div className="border-t border-border bg-muted/10 p-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Request details')}
          </p>
          <div className="flex flex-wrap items-center gap-1.5">
            <Badge variant={statusBadgeVariant(selected.status)} className="text-[10px]">
              {selected.status}
            </Badge>
            <Badge variant="outline" className="font-mono text-[10px]">
              {selected.method}
            </Badge>
          </div>
        </div>

        <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 text-[10px]">
          <div>
            <dt className="text-muted-foreground">{t('Path')}</dt>
            <dd className="mt-0.5 truncate font-mono text-foreground">{selected.path}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('Duration')}</dt>
            <dd className="mt-0.5 font-medium text-foreground">{selected.duration}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('Region')}</dt>
            <dd className="mt-0.5 font-medium text-foreground">{t(details.region)}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('Cache')}</dt>
            <dd className="mt-0.5 font-medium text-foreground">
              {selected.status === 304 ? t('Hit') : selected.status === 200 ? t('Miss') : t('N/A')}
            </dd>
          </div>
        </dl>

        <p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('SSR output')}
        </p>
        <pre className="mt-1.5 whitespace-pre-wrap font-mono text-[10px] leading-relaxed text-foreground">
          {details.output}
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
  const t = useT()
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/45">
      <div className="grid min-h-[24rem] lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div className="min-w-0 border-b border-border lg:border-b-0 lg:border-e">
          <SectionLabel>{t('Traffic')}</SectionLabel>
          <UsagePanel />
        </div>
        <div className="min-w-0">
          <SectionLabel>{t('Request logs')}</SectionLabel>
          <LogsPanel />
        </div>
      </div>
    </div>
  )
}
