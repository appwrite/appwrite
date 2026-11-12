'use client'

import { useMemo, useState } from 'react'
import { subDays } from 'date-fns'
import { Badge } from '@/components/ui/badge'
import { ArtLiveDot, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type MetricId = 'requests' | 'bandwidth' | 'builds' | 'compute'

type Metric = {
  id: MetricId
  label: string
  total: string
  points: readonly number[]
  breakdownTitle: string
  breakdown: readonly { label: string; value: number; mono?: boolean }[]
}

const METRICS: Metric[] = [
  {
    id: 'requests',
    label: 'Requests',
    total: '124K',
    points: [42, 48, 45, 58, 51, 55, 64, 59, 62, 72, 68, 74, 70, 81],
    breakdownTitle: 'Top paths',
    breakdown: [
      { label: '/', value: 42, mono: true },
      { label: '/pricing', value: 18, mono: true },
      { label: '/docs', value: 11, mono: true },
    ],
  },
  {
    id: 'bandwidth',
    label: 'Bandwidth',
    total: '18 GB',
    points: [36, 40, 44, 41, 40, 47, 52, 49, 48, 53, 55, 51, 50, 57],
    breakdownTitle: 'By asset type',
    breakdown: [
      { label: 'Static assets', value: 61 },
      { label: 'SSR responses', value: 24 },
      { label: 'Images', value: 9 },
    ],
  },
  {
    id: 'builds',
    label: 'Builds',
    total: '42',
    points: [28, 34, 26, 31, 38, 30, 35, 40, 33, 36, 42, 34, 38, 36],
    breakdownTitle: 'By trigger',
    breakdown: [
      { label: 'Git push', value: 74 },
      { label: 'Manual', value: 14 },
      { label: 'Rollback', value: 8 },
    ],
  },
  {
    id: 'compute',
    label: 'Compute',
    total: '6.2 GB-h',
    points: [32, 35, 38, 36, 35, 41, 40, 39, 44, 42, 43, 46, 42, 45],
    breakdownTitle: 'By region',
    breakdown: [
      { label: 'US East', value: 38 },
      { label: 'EU West', value: 31 },
      { label: 'AP South', value: 19 },
    ],
  },
]

type SiteLog = {
  id: string
  status: number
  method: string
  path: string
  duration: string
}

const SITE_LOGS: SiteLog[] = [
  { id: '1', status: 200, method: 'GET', path: '/pricing', duration: '124ms' },
  { id: '2', status: 304, method: 'GET', path: '/assets/logo.svg', duration: '8ms' },
  { id: '3', status: 200, method: 'POST', path: '/api/contact', duration: '342ms' },
  { id: '4', status: 404, method: 'GET', path: '/old-blog/post', duration: '12ms' },
  { id: '5', status: 500, method: 'GET', path: '/dashboard', duration: '1.2s' },
]

const SELECTED_LOG = SITE_LOGS[0]

const SSR_OUTPUT = `GET /pricing 200 · Rendered in 118ms
[console] Loaded 12 products from catalog
[console] Cached pricing tiers for 5m`

const CHART_WIDTH = 600
const CHART_HEIGHT = 200

function statusBadgeVariant(status: number) {
  if (status >= 500) return 'error' as const
  if (status >= 400) return 'warning' as const
  return 'success' as const
}

function buildChartPaths(points: readonly number[]) {
  const max = Math.max(...points) * 1.2
  const coords = points.map((value, index) => [
    (index / (points.length - 1)) * CHART_WIDTH,
    CHART_HEIGHT - (value / max) * CHART_HEIGHT,
  ])
  let line = `M ${coords[0][0]} ${coords[0][1]}`
  for (let index = 1; index < coords.length; index++) {
    const [x0, y0] = coords[index - 1]
    const [x1, y1] = coords[index]
    const midX = (x0 + x1) / 2
    line += ` C ${midX} ${y0}, ${midX} ${y1}, ${x1} ${y1}`
  }
  return { line, area: `${line} L ${CHART_WIDTH} ${CHART_HEIGHT} L 0 ${CHART_HEIGHT} Z` }
}

function TrafficPanel() {
  const t = useT()
  const [activeMetric, setActiveMetric] = useState<MetricId>('requests')
  const metric = METRICS.find((item) => item.id === activeMetric) ?? METRICS[0]
  const paths = useMemo(() => buildChartPaths(metric.points), [metric.points])
  const dateLabels = useMemo(() => {
    const today = new Date()
    return [29, 15, 0].map((daysAgo) => formatLocalizedDate(subDays(today, daysAgo), 'MMM d'))
  }, [])

  return (
    <div className="relative min-w-0 pb-6 sm:pb-12">
      <div className="product-hero-rise flex flex-wrap gap-2" style={riseStyle(60)}>
        {METRICS.map((item) => {
          const isActive = item.id === activeMetric
          return (
            <button
              key={item.id}
              type="button"
              onClick={() => setActiveMetric(item.id)}
              className={cn(
                'rounded-xl border bg-background px-3 py-2 text-start shadow-sm transition-colors dark:bg-card',
                isActive
                  ? 'border-[rgb(var(--tone-rgb)/0.5)] shadow-[0_8px_24px_-12px_rgb(var(--tone-rgb)/0.6)]'
                  : 'border-border hover:border-foreground/20',
              )}
            >
              <p className="text-[10px] font-medium text-muted-foreground">{t(item.label)}</p>
              <p
                dir="ltr"
                className={cn(
                  'text-start text-[14px] font-semibold leading-tight',
                  isActive ? 'text-[var(--tone-ink)]' : 'text-foreground',
                )}
              >
                {item.total}
              </p>
            </button>
          )
        })}
      </div>

      <div className="product-hero-rise mt-5" style={riseStyle(200)}>
        <div className="flex items-center justify-between gap-2">
          <p className="text-[11px] font-semibold text-foreground">{t('Traffic')}</p>
          <p className="text-[10px] text-muted-foreground">{t('Last 30 days')}</p>
        </div>
        <div dir="ltr" className="relative mt-3 h-[200px] sm:h-[230px]">
          <div className="absolute inset-0 flex flex-col justify-between" aria-hidden>
            {[0, 1, 2, 3].map((line) => (
              <span key={line} className="border-t border-dashed border-foreground/10" />
            ))}
          </div>
          <svg
            viewBox={`0 0 ${CHART_WIDTH} ${CHART_HEIGHT}`}
            preserveAspectRatio="none"
            className="absolute inset-0 size-full overflow-visible"
            aria-hidden
          >
            <defs>
              <linearGradient id="sites-traffic-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" style={{ stopColor: 'rgb(var(--tone-rgb))', stopOpacity: 0.28 }} />
                <stop offset="100%" style={{ stopColor: 'rgb(var(--tone-rgb))', stopOpacity: 0 }} />
              </linearGradient>
            </defs>
            <path d={paths.area} fill="url(#sites-traffic-fill)" />
            <path
              d={paths.line}
              fill="none"
              className="stroke-[var(--tone-ink)]"
              strokeWidth="2"
              vectorEffect="non-scaling-stroke"
            />
          </svg>
        </div>
        <div dir="ltr" className="mt-2 flex justify-between text-[10px] text-muted-foreground">
          {dateLabels.map((label) => (
            <span key={label}>{label}</span>
          ))}
        </div>
      </div>

      <ArtPanel
        className="mt-5 sm:absolute sm:bottom-0 sm:end-6 sm:mt-0 sm:w-[250px]"
        innerClassName="px-3.5 py-3"
        delayMs={500}
        float
        floatDelayMs={400}
      >
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(metric.breakdownTitle)}
        </p>
        <div className="mt-2.5 space-y-2">
          {metric.breakdown.map((row) => (
            <div key={row.label} className="flex items-center gap-2 text-[10px]">
              <span
                dir={row.mono ? 'ltr' : undefined}
                className={cn('w-20 shrink-0 truncate text-start text-foreground', row.mono && 'font-mono')}
              >
                {row.mono ? row.label : t(row.label)}
              </span>
              <div className="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                <div
                  className="h-full rounded-full bg-[var(--tone-ink)] transition-[width] duration-300"
                  style={{ width: `${row.value}%` }}
                />
              </div>
              <span dir="ltr" className="w-7 shrink-0 text-end text-muted-foreground">{row.value}%</span>
            </div>
          ))}
        </div>
      </ArtPanel>
    </div>
  )
}

function LogsPanel() {
  const t = useT()

  return (
    <div className="min-w-0">
      <div className="product-hero-rise flex items-center justify-between gap-2" style={riseStyle(150)}>
        <p className="text-[11px] font-semibold text-foreground">{t('Request logs')}</p>
        <span className="flex items-center gap-1.5 text-[10px] text-muted-foreground">
          <ArtLiveDot />
          {t('Live')}
        </span>
      </div>

      <div className="mt-3 space-y-1.5 [mask-image:linear-gradient(to_bottom,black_70%,transparent)]">
        {SITE_LOGS.map((log, index) => {
          const selected = log.id === SELECTED_LOG.id
          return (
            <div
              key={log.id}
              className={cn(
                'product-hero-rise flex items-center gap-3 rounded-lg border bg-background/95 px-3 py-2 dark:bg-card',
                selected
                  ? 'border-[rgb(var(--tone-rgb)/0.45)] shadow-[0_10px_28px_-16px_rgb(var(--tone-rgb)/0.7)]'
                  : 'border-border',
              )}
              style={riseStyle(250 + index * 90)}
            >
              <Badge variant={statusBadgeVariant(log.status)} className="shrink-0 text-[10px]">
                {log.status}
              </Badge>
              <span dir="ltr" className="w-9 shrink-0 font-mono text-[10px] text-muted-foreground">
                {log.method}
              </span>
              <span dir="ltr" className="min-w-0 flex-1 truncate text-start font-mono text-[11px] text-foreground">
                {log.path}
              </span>
              <span dir="ltr" className="shrink-0 font-mono text-[10px] text-muted-foreground">{log.duration}</span>
            </div>
          )
        })}
      </div>

      <ArtPanel className="-mt-6 sm:ms-8" innerClassName="product-tone-shadow p-3.5" delayMs={800}>
        <div className="flex items-center justify-between gap-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Request details')}
          </p>
          <span className="flex items-center gap-1.5">
            <Badge variant={statusBadgeVariant(SELECTED_LOG.status)} className="text-[10px]">
              {SELECTED_LOG.status}
            </Badge>
            <Badge variant="outline" className="font-mono text-[10px]">
              {SELECTED_LOG.method}
            </Badge>
          </span>
        </div>
        <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[10px] sm:grid-cols-4">
          <div>
            <dt className="text-muted-foreground">{t('Path')}</dt>
            <dd dir="ltr" className="mt-0.5 truncate text-start font-mono text-foreground">{SELECTED_LOG.path}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('Duration')}</dt>
            <dd dir="ltr" className="mt-0.5 text-start font-medium text-foreground">{SELECTED_LOG.duration}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('Region')}</dt>
            <dd className="mt-0.5 font-medium text-foreground">{t('US East')}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('Cache')}</dt>
            <dd className="mt-0.5 font-medium text-foreground">{t('Miss')}</dd>
          </div>
        </dl>
        <p className="mt-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('SSR output')}
        </p>
        <pre
          dir="ltr"
          className="mt-1.5 whitespace-pre-wrap rounded-lg bg-muted/40 px-2.5 py-2 font-mono text-[10px] leading-relaxed text-foreground"
        >
          {SSR_OUTPUT}
        </pre>
      </ArtPanel>
    </div>
  )
}

export function SitesObservabilityVisual() {
  return (
    <div className="grid gap-10 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:gap-12">
      <TrafficPanel />
      <LogsPanel />
    </div>
  )
}
