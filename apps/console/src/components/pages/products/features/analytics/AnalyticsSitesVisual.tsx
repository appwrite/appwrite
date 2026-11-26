import { Check, Globe } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import {
  ArtChip,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import {
  ANALYTICS_ART_TREND,
  AnalyticsArtRow,
  AnalyticsAreaChart,
} from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import { useT } from '@/lib/i18n/translate'

const STATS = [
  { label: 'Pageviews', value: '3.9K' },
  { label: 'Bounce rate', value: '38%' },
  { label: 'Visit duration', value: '1m 42s' },
] as const

const SOURCES = [
  { label: 'google.com', value: '412', percent: 100 },
  { label: 'chatgpt.com', value: '188', percent: 46 },
  { label: 'github.com', value: '121', percent: 29 },
] as const

/**
 * The switch on the site create form, and the Analytics card that site then
 * shows on its own page: no script, the numbers arrive with the deployment.
 */
export function AnalyticsSitesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-4 pt-2">
      <ArtPanel
        className="w-full sm:w-[310px]"
        delayMs={60}
        innerClassName="flex items-start gap-3 px-3.5 py-3"
      >
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Create site')}
          </p>
          <p className="mt-1.5 text-[12.5px] font-semibold text-foreground">{t('Enable analytics')}</p>
          <p className="mt-0.5 text-[10.5px] leading-4 text-muted-foreground">
            {t('Production domains only. Preview deployments are not tracked.')}
          </p>
        </div>
        <Switch
          checked
          disabled
          className="mt-1 scale-90 disabled:opacity-100 data-[state=checked]:bg-[var(--tone-ink)]"
          aria-hidden
        />
      </ArtPanel>

      <div className="ms-10 h-8 border-s border-dashed border-foreground/25 sm:h-10" aria-hidden />

      <ArtPanel
        className="w-full sm:ms-auto sm:w-[400px]"
        delayMs={420}
        float
        floatDelayMs={300}
        innerClassName="product-tone-shadow p-0 overflow-hidden"
      >
        <div className="flex items-center gap-2.5 border-b border-border px-3.5 py-2.5">
          <ArtIconBadge icon={Globe} tone="secondary" />
          <div className="min-w-0 flex-1">
            <p className="text-[12px] font-semibold text-foreground">{t('Analytics')}</p>
            <p dir="ltr" className="truncate text-[10.5px] text-muted-foreground">
              my-store.com · {t('Last 24 hours')}
            </p>
          </div>
          <ArtLiveDot />
        </div>
        <div className="grid gap-3 px-3.5 py-3 sm:grid-cols-[minmax(0,1fr)_150px]">
          <div className="min-w-0">
            <p className="text-[10px] text-muted-foreground">{t('Visitors')}</p>
            <p className="mt-0.5 flex items-baseline gap-1.5">
              <span dir="ltr" className="font-aeonik-pro text-[20px] leading-none tracking-tight text-foreground">
                1,284
              </span>
              <span dir="ltr" className="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                +12%
              </span>
            </p>
            <div className="mt-2 text-foreground">
              <AnalyticsAreaChart values={ANALYTICS_ART_TREND.slice(6)} className="h-[54px]" height={54} />
            </div>
            <div className="mt-2.5 grid grid-cols-3 gap-2 border-t border-border/70 pt-2">
              {STATS.map((stat) => (
                <div key={stat.label} className="min-w-0">
                  <p className="truncate text-[9.5px] text-muted-foreground">{t(stat.label)}</p>
                  <p dir="ltr" className="mt-0.5 text-[11.5px] font-medium tabular-nums text-foreground">
                    {stat.value}
                  </p>
                </div>
              ))}
            </div>
          </div>
          <div className="hidden min-w-0 sm:block">
            <p className="px-2 pb-1 text-[10px] text-muted-foreground">{t('Top sources')}</p>
            <div className="space-y-0.5">
              {SOURCES.map((source, index) => (
                <AnalyticsArtRow
                  key={source.label}
                  label={source.label}
                  value={source.value}
                  percent={source.percent}
                  highlight={index === 0}
                />
              ))}
            </div>
          </div>
        </div>
      </ArtPanel>

      <ArtChip className="end-0 top-1 hidden sm:block" delayMs={800} floatDelayMs={500}>
        <span className="flex items-center gap-1.5 text-[11px] text-foreground">
          <span className="flex size-4 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
            <Check className="size-2.5" strokeWidth={3} aria-hidden />
          </span>
          {t('No script to paste')}
        </span>
      </ArtChip>
    </div>
  )
}
