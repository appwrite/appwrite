'use client'

import { useMemo } from 'react'
import { subHours } from 'date-fns'
import { ArrowUpRight } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { FirewallTrafficChart } from '@/components/pages/projects/$projectId/firewall/_components/FirewallTrafficChart'
import { OVERVIEW_CHART_HEIGHT } from '@/components/pages/projects/$projectId/overview/chart-panel'
import {
  getFirewallTrafficSeriesTotals,
  sortFirewallTrafficSeriesByValueAsc,
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
}: {
  label: string
  value: string | number
  change?: number
}) {
  return (
    <div className="min-w-0">
      <p className="text-[12px] text-muted-foreground">{label}</p>
      <div className="mt-0.5 flex items-baseline gap-x-2">
        <span className="text-[20px] font-semibold tabular-nums text-foreground">
          {typeof value === 'number' ? value.toLocaleString() : value}
        </span>
        {change !== undefined ? (
          <span
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
    </div>
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
    { label: t('Passed'), value: totals.requests, change: 8 },
    { label: t('Denied'), value: totals.denied, change: 18 },
    { label: t('Challenged'), value: totals.challenged, change: 6 },
    { label: t('Rate limited'), value: totals.rateLimited, change: -3 },
    { label: t('Redirected'), value: totals.redirected, change: 2 },
    { label: t('Block rate'), value: `${blockRate}%`, change: 4 },
  ]

  return (
    <div className="w-full border-t border-border bg-background">
      <div className="flex flex-col-reverse gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span className="text-[24px] font-semibold tabular-nums text-foreground">
              {totalRequests.toLocaleString()}
            </span>
            <span className="text-[13px] text-muted-foreground">
              {t('requests')}
            </span>
            <span className="text-[12px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
              +{mock.requestsChange}% {t('vs previous period')}
            </span>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex h-9 items-center rounded-md border border-border bg-muted/20 px-3 text-[12px] text-muted-foreground">
            {t('Last 24 hours')}
          </span>
          <DocsRouteLink
            href="/docs/products/firewall/monitor"
            className="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-background px-3 text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground"
          >
            {t('Monitor docs')}
            <ArrowUpRight className="size-3.5" aria-hidden />
          </DocsRouteLink>
        </div>
      </div>

      <div className="px-2 pb-2 pt-4 sm:px-4 sm:pt-4 lg:px-6">
        <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 px-2 sm:px-2">
          {seriesByValueAsc.map((series) => (
            <div key={series.key} className="flex items-center gap-1.5">
              <span
                className="h-2 w-2 rounded-full"
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

      <div className="grid grid-cols-2 border-t border-border sm:grid-cols-3 xl:grid-cols-6">
        {metrics.map((metric, index) => {
          const count = metrics.length
          const isLast = index === count - 1
          const lastRowStartMobile = count - (count % 2 || 2)
          const lastRowStartSm = count - (count % 3 || 3)
          const showBottomBorderMobile = index < lastRowStartMobile
          const showBottomBorderSm = index < lastRowStartSm
          const showEndBorderMobile = index % 2 === 0 && !isLast
          const showEndBorderSm = index % 3 !== 2 && !isLast
          return (
            <div
              key={metric.label}
              className={cn(
                'px-4 py-3 sm:px-6',
                showBottomBorderMobile && 'border-b border-border',
                !showBottomBorderSm && 'sm:border-b-0',
                'xl:border-b-0',
                showEndBorderMobile && 'border-e border-border sm:border-e-0',
                showEndBorderSm && 'sm:border-e sm:border-border xl:border-e-0',
                !isLast && 'xl:border-e xl:border-border',
              )}
            >
              <MetricTile
                label={metric.label}
                value={metric.value}
                change={typeof metric.change === 'number' ? metric.change : undefined}
              />
            </div>
          )
        })}
      </div>
    </div>
  )
}
