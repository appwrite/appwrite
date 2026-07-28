'use client'

import { useMemo } from 'react'
import { subHours } from 'date-fns'
import { Target } from 'lucide-react'
import { FirewallImpactChart } from '@/components/pages/projects/$projectId/firewall/_components/FirewallImpactChart'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import type { FirewallImpactPoint } from '@/lib/firewall/types'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

const MOCK_HOURS = 24
const CHART_HEIGHT = 200

function buildMockImpactSeries(hours: number = MOCK_HOURS): {
  series: FirewallImpactPoint[]
  matched: number
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
    dateRange: { from, to },
  }
}

export function FirewallImpactVisual() {
  const t = useT()
  const mock = useMemo(() => buildMockImpactSeries(), [])

  return (
    <ProductFeatureVisualFrame title={t('Estimated impact')}>
      <div className="space-y-4">
        <div className="flex items-end justify-between gap-3">
          <p className="max-w-[16rem] text-[12px] leading-5 text-muted-foreground">
            {t('See how much recent traffic a draft rule would affect.')}
          </p>
          <div className="text-end">
            <p className="flex items-center justify-end gap-1.5 text-[11px] text-muted-foreground">
              <Target className="size-3.5" aria-hidden />
              {t('Matched')}
            </p>
            <p className="mt-0.5 text-[20px] font-semibold tabular-nums text-foreground">
              {mock.matched.toLocaleString()}
            </p>
          </div>
        </div>

        <FirewallImpactChart
          series={mock.series}
          dateRange={mock.dateRange}
          chartInterval={DEFAULT_USAGE_CHART_INTERVAL}
          height={CHART_HEIGHT}
        />
      </div>
    </ProductFeatureVisualFrame>
  )
}
