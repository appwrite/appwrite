'use client'

import { useMemo } from 'react'
import { subHours } from 'date-fns'
import { Target, Timer } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  FIREWALL_IMPACT_MATCHED_COLOR,
  FirewallImpactChart,
} from '@/components/pages/projects/$projectId/firewall/_components/FirewallImpactChart'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import type { FirewallImpactPoint } from '@/lib/firewall/types'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

const MOCK_HOURS = 24
const CHART_HEIGHT = 190

const DRAFT_CONDITIONS = ['path starts_with /v1', 'method == POST'] as const

function buildMockImpactSeries(hours: number = MOCK_HOURS): {
  series: FirewallImpactPoint[]
  matched: number
  total: number
  dateRange: { from: Date; to: Date }
} {
  const to = new Date()
  const from = subHours(to, hours - 1)
  const series: FirewallImpactPoint[] = Array.from({ length: hours }, (_, index) => {
    const day = subHours(to, hours - 1 - index)
    const wave = Math.sin(index / 2.4)
    const peak = index > 16 && index < 21 ? 1.35 : 1
    const total = Math.max(
      120,
      Math.round((720 + wave * 180 + (index % 6) * 35) * peak),
    )
    const matchRate = 0.1 + (Math.sin(index / 3.1) + 1) * 0.05
    const matched = Math.min(total, Math.round(total * matchRate))
    return {
      date: day.toISOString(),
      day,
      fullDate: formatLocalizedDate(day, 'MMM d, yyyy HH:mm'),
      total,
      matched,
    }
  })

  return {
    series,
    matched: series.reduce((sum, point) => sum + point.matched, 0),
    total: series.reduce((sum, point) => sum + point.total, 0),
    dateRange: { from, to },
  }
}

export function FirewallImpactVisual() {
  const t = useT()
  const mock = useMemo(() => buildMockImpactSeries(), [])
  const matchedShare = mock.total > 0 ? ((mock.matched / mock.total) * 100).toFixed(1) : '0.0'

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-2">
      <div className="relative z-[1] flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <ArtPanel className="w-full sm:w-[270px]" innerClassName="product-tone-shadow px-3.5 py-3" delayMs={60}>
          <div className="flex items-center gap-2.5">
            <ArtIconBadge icon={Timer} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[12px] font-semibold text-foreground">{t('Rate limit public API')}</p>
              <p className="text-[11px] text-muted-foreground">{t('Estimated impact')}</p>
            </div>
            <Badge variant="warning" className="shrink-0 text-[10px]">
              {t('Draft')}
            </Badge>
          </div>
          <div className="mt-2.5 flex flex-wrap gap-1.5">
            {DRAFT_CONDITIONS.map((condition, index) => (
              <code
                key={condition}
                dir="ltr"
                className="product-hero-rise rounded-md border border-border bg-muted/30 px-1.5 py-0.5 font-mono text-[10.5px] text-foreground"
                style={riseStyle(300 + index * 120)}
              >
                {condition}
              </code>
            ))}
          </div>
          <div className="mt-3 flex items-center justify-between gap-3 border-t border-border/70 pt-2.5">
            <span className="text-[11px] text-muted-foreground">{t('Enable rule')}</span>
            <Switch checked={false} disabled className="scale-75" aria-hidden />
          </div>
        </ArtPanel>

        <ArtPanel
          className="w-fit sm:mt-8"
          innerClassName="px-3.5 py-3"
          delayMs={420}
          float
          floatDelayMs={500}
        >
          <p className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <Target className="size-3.5" aria-hidden />
            {t('Matched')}
          </p>
          <div className="mt-0.5 flex items-baseline gap-2">
            <span dir="ltr" className="font-aeonik-pro text-[24px] leading-none tracking-tight tabular-nums text-foreground">
              {mock.matched.toLocaleString()}
            </span>
            <span dir="ltr" className="text-[12px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
              {matchedShare}%
            </span>
          </div>
          <div className="mt-2.5 space-y-1">
            <p className="flex items-center gap-1.5 text-[10.5px] text-muted-foreground">
              <span className="w-3 border-t border-dashed border-muted-foreground" aria-hidden />
              {t('Total traffic')}
            </p>
            <p className="flex items-center gap-1.5 text-[10.5px] text-muted-foreground">
              <span className="h-0.5 w-3 rounded-full" style={{ backgroundColor: FIREWALL_IMPACT_MATCHED_COLOR }} aria-hidden />
              {t('Matched by rule')}
            </p>
          </div>
        </ArtPanel>
      </div>

      <div className="product-hero-rise relative mt-4" style={riseStyle(600)}>
        <div className="mb-1 flex items-center justify-between gap-3 px-1">
          <span className="text-[11px] font-medium text-foreground">{t('Matched requests over time')}</span>
          <span className="rounded-full border border-border bg-background px-2 py-0.5 text-[10px] text-muted-foreground shadow-sm dark:bg-card">
            {t('Last 24 hours')}
          </span>
        </div>
        <FirewallImpactChart
          series={mock.series}
          dateRange={mock.dateRange}
          chartInterval={DEFAULT_USAGE_CHART_INTERVAL}
          height={CHART_HEIGHT}
        />
      </div>
    </div>
  )
}
