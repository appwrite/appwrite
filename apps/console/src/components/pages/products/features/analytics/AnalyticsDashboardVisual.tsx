import { CalendarRange, Filter, GitCompareArrows, X } from 'lucide-react'
import { ArtChip, ArtPanel } from '@/components/pages/products/_components/ArtParts'
import {
  ANALYTICS_ART_PREVIOUS,
  ANALYTICS_ART_TREND,
  AnalyticsArtRow,
  AnalyticsAreaChart,
} from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TABS = [
  { label: 'Visitors', value: '4,108', change: '+31%', active: true },
  { label: 'Pageviews', value: '11.2K', change: '+24%', active: false },
  { label: 'Bounce rate', value: '36%', change: '-5%', active: false },
] as const

const COUNTRIES = [
  { code: 'US', label: 'United States', value: '1.4K', percent: 100 },
  { code: 'DE', label: 'Germany', value: '690', percent: 49 },
  { code: 'IN', label: 'India', value: '530', percent: 37 },
] as const

function CodeBadge({ code }: { code: string }) {
  return (
    <span
      dir="ltr"
      className="flex h-4 w-5 items-center justify-center rounded-[3px] border border-border bg-muted/50 font-mono text-[8.5px] font-semibold text-muted-foreground"
    >
      {code}
    </span>
  )
}

/**
 * The overview chart with an active filter, and a breakdown card floating
 * over its corner: click a value anywhere and the whole page follows.
 */
export function AnalyticsDashboardVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-24 pt-10 sm:pb-20">
      <ArtChip className="start-0 top-0" delayMs={500} floatDelayMs={0}>
        <span className="flex items-center gap-1.5 text-[11px] text-foreground">
          <Filter className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Source is github.com')}
          <X className="size-3 text-muted-foreground" aria-hidden />
        </span>
      </ArtChip>
      <ArtChip className="end-0 top-0 hidden sm:block" delayMs={620} floatDelayMs={700}>
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <CalendarRange className="size-3" aria-hidden />
          {t('Last 30 days')}
          <span className="text-border">·</span>
          <GitCompareArrows className="size-3" aria-hidden />
          {t('vs previous')}
        </span>
      </ArtChip>

      <ArtPanel delayMs={80} innerClassName="product-tone-shadow overflow-hidden p-0">
        <div className="grid grid-cols-3 border-b border-border">
          {TABS.map((tab) => (
            <div
              key={tab.label}
              className={cn(
                'border-b-2 px-3.5 py-2.5',
                tab.active ? 'border-[var(--tone-ink)]' : 'border-transparent',
              )}
            >
              <p className="truncate text-[10.5px] text-muted-foreground">{t(tab.label)}</p>
              <p className="mt-0.5 flex items-baseline gap-1.5">
                <span dir="ltr" className="font-aeonik-pro text-[16px] tracking-tight text-foreground">
                  {tab.value}
                </span>
                <span dir="ltr" className="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                  {tab.change}
                </span>
              </p>
            </div>
          ))}
        </div>
        <div className="px-3.5 pb-10 pt-4 text-foreground">
          <AnalyticsAreaChart
            values={ANALYTICS_ART_TREND}
            previous={ANALYTICS_ART_PREVIOUS}
            className="h-[110px]"
            height={110}
          />
        </div>
      </ArtPanel>

      {/* Breakdown card overlapping the chart's corner. */}
      <ArtPanel
        className="absolute -bottom-1 end-[-4%] w-[min(270px,78%)]"
        delayMs={320}
        float
        floatDelayMs={400}
        innerClassName="p-2"
      >
        <p className="px-2 pb-1.5 pt-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Countries')}
        </p>
        <div className="space-y-0.5">
          {COUNTRIES.map((country, index) => (
            <AnalyticsArtRow
              key={country.code}
              label={t(country.label)}
              value={country.value}
              percent={country.percent}
              highlight={index === 0}
              leading={<CodeBadge code={country.code} />}
            />
          ))}
        </div>
      </ArtPanel>
    </div>
  )
}
