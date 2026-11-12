'use client'

import { useMemo } from 'react'
import { subHours } from 'date-fns'
import { ArrowUpRight } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { FirewallTrafficChart } from '@/components/pages/projects/$projectId/firewall/_components/FirewallTrafficChart'
import { OVERVIEW_CHART_HEIGHT } from '@/components/pages/projects/$projectId/overview/chart-panel'
import { ArtLiveDot, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import {
  FIREWALL_TRAFFIC_SERIES,
  getFirewallTrafficSeriesTotals,
  sortFirewallTrafficSeriesByValueAsc,
  type FirewallTrafficSeriesKey,
} from '@/lib/firewall/traffic-series'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import type { FirewallTrafficPoint } from '@/lib/usage/firewall-events'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { cn } from '@/lib/utils'

const MOCK_HOURS = 24
const PRODUCT_TRAFFIC_CHART_HEIGHT = OVERVIEW_CHART_HEIGHT + 40

function buildMockTrafficSeries(hours: number = MOCK_HOURS): {
  points: FirewallTrafficPoint[]
  dateRange: { from: Date; to: Date }
  requestsChange: number
} {
  const to = new Date()
  const from = subHours(to, hours - 1)
  const points: FirewallTrafficPoint[] = Array.from(
    { length: hours },
    (_, index) => {
      const day = subHours(to, hours - 1 - index)
      const wave = Math.sin(index / 2.2)
      const peak = index > 15 && index < 21 ? 1.4 : 1
      const requests = Math.max(
        180,
        Math.round((920 + wave * 220 + (index % 5) * 40) * peak),
      )
      const denied = Math.round(requests * (0.04 + (Math.sin(index / 3) + 1) * 0.02))
      const challenged = Math.round(
        requests * (0.015 + (Math.cos(index / 4) + 1) * 0.01),
      )
      const rateLimited = Math.round(
        requests * (0.01 + (Math.sin(index / 5) + 1) * 0.008),
      )
      const redirected = Math.round(requests * 0.004)

      return {
        date: day.toISOString(),
        day,
        fullDate: formatLocalizedDate(day, 'MMM d, yyyy HH:mm'),
        requests,
        denied,
        challenged,
        rateLimited,
        redirected,
      }
    },
  )

  return {
    points,
    dateRange: { from, to },
    requestsChange: 12,
  }
}

function MetricTile({
  label,
  value,
  change,
  color,
  delayMs,
}: {
  label: string
  value: string | number
  change?: number
  color?: string
  delayMs: number
}) {
  return (
    <ArtPanel className="min-w-0" innerClassName="px-3.5 py-3" delayMs={delayMs}>
      <p className="flex items-center gap-1.5 truncate text-[12px] text-muted-foreground">
        <span
          className={cn('size-2 shrink-0 rounded-full', !color && 'bg-[var(--tone-ink)]')}
          style={color ? { backgroundColor: color } : undefined}
          aria-hidden
        />
        {label}
      </p>
      <div className="mt-1 flex items-baseline gap-x-2">
        <span dir="ltr" className="font-aeonik-pro text-[22px] leading-none tracking-tight tabular-nums text-foreground">
          {typeof value === 'number' ? value.toLocaleString() : value}
        </span>
        {change !== undefined ? (
          <span
            dir="ltr"
            className={cn(
              'text-[12px] font-medium tabular-nums',
              change > 0 && 'text-emerald-600 dark:text-emerald-400',
              change < 0 && 'text-amber-600 dark:text-amber-400',
              change === 0 && 'text-muted-foreground',
            )}
          >
            {change > 0 ? '+' : ''}
            {change}%
          </span>
        ) : null}
      </div>
    </ArtPanel>
  )
}

export function FirewallMonitorSection() {
  const t = useT()
  const mock = useMemo(() => buildMockTrafficSeries(), [])
  const totals = useMemo(
    () => getFirewallTrafficSeriesTotals(mock.points),
    [mock.points],
  )
  const seriesByValueAsc = useMemo(
    () => sortFirewallTrafficSeriesByValueAsc(totals),
    [totals],
  )
  const seriesColor = useMemo(
    () =>
      Object.fromEntries(FIREWALL_TRAFFIC_SERIES.map((series) => [series.key, series.color])) as Record<
        FirewallTrafficSeriesKey,
        string
      >,
    [],
  )

  const totalRequests =
    totals.requests +
    totals.denied +
    totals.challenged +
    totals.rateLimited +
    totals.redirected

  const blockRate =
    totalRequests > 0
      ? (((totals.denied + totals.rateLimited) / totalRequests) * 100).toFixed(1)
      : '0.0'

  const metrics = [
    { label: t('Passed'), value: totals.requests, change: 8, color: seriesColor.requests },
    { label: t('Denied'), value: totals.denied, change: 18, color: seriesColor.denied },
    { label: t('Challenged'), value: totals.challenged, change: 6, color: seriesColor.challenged },
    { label: t('Rate limited'), value: totals.rateLimited, change: -3, color: seriesColor.rateLimited },
    { label: t('Redirected'), value: totals.redirected, change: 2, color: seriesColor.redirected },
    { label: t('Block rate'), value: `${blockRate}%`, change: 4 },
  ]

  return (
    <div className="relative w-full">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <div
          className="product-hero-rise flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
          style={riseStyle(0)}
        >
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span dir="ltr" className="font-aeonik-pro text-[32px] leading-none tracking-tight tabular-nums text-foreground">
              {totalRequests.toLocaleString()}
            </span>
            <span className="text-[13px] text-muted-foreground">{t('requests')}</span>
            <span className="text-[12px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
              <span dir="ltr">+{mock.requestsChange}%</span> {t('vs previous period')}
            </span>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-[12px] text-muted-foreground shadow-sm dark:bg-card">
              <ArtLiveDot className="size-1.5" />
              {t('Last 24 hours')}
            </span>
            <DocsRouteLink
              href="/docs/products/firewall/monitor"
              className="group/docs inline-flex h-8 items-center gap-1.5 px-2 text-[12px] font-medium text-foreground/80 transition-colors hover:text-[var(--tone-ink)]"
            >
              {t('Monitor docs')}
              <ArrowUpRight className="size-3.5 rtl:-scale-x-100" aria-hidden />
            </DocsRouteLink>
          </div>
        </div>

        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
          {metrics.map((metric, index) => (
            <MetricTile
              key={metric.label}
              label={metric.label}
              value={metric.value}
              change={metric.change}
              color={metric.color}
              delayMs={150 + index * 80}
            />
          ))}
        </div>

        <div className="product-hero-rise mt-8" style={riseStyle(500)}>
          <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 px-1">
            {seriesByValueAsc.map((series) => (
              <div key={series.key} className="flex items-center gap-1.5">
                <span
                  className="size-2 rounded-full"
                  style={{ backgroundColor: series.color }}
                />
                <span className="text-[12px] text-muted-foreground">
                  {t(series.label)}
                </span>
              </div>
            ))}
          </div>

          <FirewallTrafficChart
            data={mock.points}
            dateRange={mock.dateRange}
            chartInterval={DEFAULT_USAGE_CHART_INTERVAL}
            height={PRODUCT_TRAFFIC_CHART_HEIGHT}
            gradientSuffix="product"
            className="w-full"
          />
        </div>
      </div>
    </div>
  )
}
