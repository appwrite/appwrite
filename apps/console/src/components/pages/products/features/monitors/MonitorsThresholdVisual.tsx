import { History } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  MonitorsCaption,
  MonitorsField,
  MonitorsUsageChart,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const RANGES = ['12h', '24h', '7d', '30d'] as const

/** Hourly outbound bandwidth (GB) with two breaches of 5 GB, so the backtest reads 2×. */
const HISTORY = [
  1.2, 1.1, 1.0, 1.1, 1.4, 2.2, 3.6, 5.4, 5.8, 4.6, 3.2, 2.6, 2.4, 2.5, 2.8,
  3.1, 3.6, 4.2, 4.8, 5.5, 6.1, 6.6, 6.9, 6.2,
] as const

/** The Condition step of Create monitor: the sentence, the history chart, the draggable line, and the backtest. */
export function MonitorsThresholdVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-4">
        <MonitorsCaption>{t('Condition')}</MonitorsCaption>
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          <label className="space-y-1">
            <span className="block text-[10.5px] text-muted-foreground">
              {t('Metric')}
            </span>
            <MonitorsField
              className="w-full"
              select
              value={t('Outbound bandwidth')}
            />
          </label>
          <label className="space-y-1">
            <span className="block text-[10.5px] text-muted-foreground">
              {t('Applies to')}
            </span>
            <MonitorsField
              className="w-full"
              select
              value={t('The whole project')}
            />
          </label>
        </div>
        <p className="mt-3 flex flex-wrap items-center gap-1.5 text-[11.5px] text-foreground">
          {t('Alert when the value is')}
          <MonitorsField select value={t('greater than')} />
          <MonitorsField mono className="w-12 justify-center" value="5" />
          <MonitorsField select value="GB" />
        </p>
        <p className="mt-2 flex flex-wrap items-center gap-1.5 text-[11.5px] text-foreground">
          {t('Counted over')}
          <MonitorsField select value={t('1 hour')} />
        </p>

        <div className="mt-4 rounded-lg border border-border/80 p-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span
              className="product-hero-rise inline-flex items-center gap-1.5 rounded-full bg-[rgb(var(--tone-rgb)/0.14)] px-2 py-0.5 text-[10.5px] font-medium text-[var(--tone-ink)]"
              style={riseStyle(600)}
            >
              <History className="size-3" aria-hidden />
              {t('Would have fired 2× in the last 24 hours')}
            </span>
            <span
              className="flex gap-0.5 rounded-md bg-muted p-0.5"
              aria-hidden
            >
              {RANGES.map((range) => (
                <span
                  key={range}
                  dir="ltr"
                  className={cn(
                    'rounded px-1.5 py-0.5 font-mono text-[9.5px]',
                    range === '24h'
                      ? 'bg-background text-foreground shadow-sm dark:bg-card'
                      : 'text-muted-foreground',
                  )}
                >
                  {range}
                </span>
              ))}
            </span>
          </div>
          <MonitorsUsageChart
            className="mt-6 text-foreground"
            values={HISTORY}
            threshold={5}
            max={8}
            height={112}
            showHandle
            markLast={false}
            thresholdLabel={
              <>
                {t('Threshold')} <span dir="ltr">5 GB</span>
              </>
            }
          />
          <p
            dir="ltr"
            className="mt-2 flex justify-between text-[9.5px] text-muted-foreground"
          >
            <span>12 AM</span>
            <span>6 AM</span>
            <span>12 PM</span>
            <span>6 PM</span>
          </p>
        </div>
      </ArtPanel>
    </div>
  )
}
