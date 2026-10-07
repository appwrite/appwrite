import {
  BarChart2,
  Bot,
  Check,
  Cookie,
  Smartphone,
  Sparkles,
  User,
  type LucideIcon,
} from 'lucide-react'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import {
  ANALYTICS_ART_PREVIOUS,
  ANALYTICS_ART_TREND,
  AnalyticsAreaChart,
} from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import { useT } from '@/lib/i18n/translate'

/** Traffic arriving at the property: people, an AI referral, a crawler, an app. */
const INCOMING: {
  icon: LucideIcon
  label: string
  detail: string
  tone: 'primary' | 'secondary' | 'neutral'
  delayMs: number
}[] = [
  { icon: User, label: 'Visitor', detail: 'Berlin · Chrome', tone: 'primary', delayMs: 120 },
  { icon: Sparkles, label: 'From ChatGPT', detail: 'AI channel', tone: 'secondary', delayMs: 240 },
  { icon: Bot, label: 'GPTBot', detail: 'AI crawler', tone: 'neutral', delayMs: 360 },
  { icon: Smartphone, label: 'Mobile app', detail: 'screen_view', tone: 'neutral', delayMs: 480 },
]

const GUARANTEES = ['No cookies set', 'IP discarded', 'ID rotates daily'] as const

/** Humans vs bots split shown under the chart. */
const SPLIT = [
  { share: 82, className: 'bg-[var(--tone-ink)]' },
  { share: 11, className: 'bg-amber-500' },
  { share: 7, className: 'bg-teal-500' },
] as const

function PropertyNode() {
  const t = useT()
  return (
    <div className="relative z-[1] isolate mx-auto w-[min(230px,100%)] lg:mx-0 lg:w-[230px]">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 -z-10 size-[280px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.22),transparent)] blur-2xl"
        aria-hidden
      />
      <ArtPanel
        delayMs={0}
        innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] p-3.5 dark:border-[rgb(var(--tone-rgb)/0.4)]"
      >
        <div className="flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
            <BarChart2 className="size-[18px]" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-[13px] font-semibold text-foreground">{t('Analytics')}</p>
            <p dir="ltr" className="truncate font-mono text-[10.5px] text-muted-foreground">
              example.com
            </p>
          </div>
          <ArtLiveDot className="ms-auto" />
        </div>
        <ul className="mt-3 space-y-1.5 border-t border-border/70 pt-3">
          {GUARANTEES.map((item) => (
            <li key={item} className="flex items-center gap-2 text-[11.5px] text-foreground">
              <span className="flex size-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                <Check className="size-2.5" strokeWidth={3} aria-hidden />
              </span>
              {t(item)}
            </li>
          ))}
        </ul>
      </ArtPanel>
    </div>
  )
}

function DashboardPanel() {
  const t = useT()
  return (
    <ArtPanel delayMs={620} innerClassName="product-tone-shadow px-3.5 pb-3 pt-3">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Visitors')}
          </p>
          <p className="mt-1 flex items-baseline gap-1.5">
            <span dir="ltr" className="font-aeonik-pro text-[22px] leading-none tracking-tight text-foreground">
              48.2K
            </span>
            <span dir="ltr" className="text-[10.5px] font-medium text-emerald-600 dark:text-emerald-400">
              +18%
            </span>
          </p>
        </div>
        <span className="rounded-md border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground">
          {t('30 days')}
        </span>
      </div>
      <div className="mt-2 text-foreground">
        <AnalyticsAreaChart
          values={ANALYTICS_ART_TREND}
          previous={ANALYTICS_ART_PREVIOUS}
          className="h-[78px]"
          height={78}
        />
      </div>
      <div className="mt-3 flex h-1.5 gap-[2px] overflow-hidden rounded-full" aria-hidden>
        {SPLIT.map((segment, index) => (
          <span
            key={index}
            className={`h-full first:rounded-s-full last:rounded-e-full ${segment.className}`}
            style={{ width: `${segment.share}%` }}
          />
        ))}
      </div>
      <div className="mt-1.5 flex justify-between text-[10px] text-muted-foreground">
        <span>
          {t('Humans')} <span dir="ltr" className="text-foreground">82%</span>
        </span>
        <span>
          {t('Bots')} <span dir="ltr" className="text-foreground">18%</span>
        </span>
      </div>
    </ArtPanel>
  )
}

/**
 * Traffic flows in from the start side (people, AI referrals, crawlers, apps),
 * passes through the property where nothing personal is kept, and comes out
 * as a dashboard on the end side.
 */
export function AnalyticsHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto max-w-5xl py-4 text-start">
      <div className="grid items-center gap-6 lg:grid-cols-[minmax(0,1fr)_3rem_230px_3rem_minmax(0,1.15fr)] lg:gap-0">
        <div className="relative z-[1] min-w-0 space-y-2">
          {INCOMING.map((item, index) => (
            <ArtPanel
              key={item.label}
              delayMs={item.delayMs}
              float
              floatDelayMs={index * 450}
              innerClassName="flex items-center gap-2.5 px-3 py-2"
            >
              <ArtIconBadge icon={item.icon} tone={item.tone} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[12px] font-semibold text-foreground">{t(item.label)}</span>
                <span dir="ltr" className="block truncate font-mono text-[10px] text-muted-foreground">
                  {t(item.detail)}
                </span>
              </span>
            </ArtPanel>
          ))}
        </div>

        <div className="relative z-0 hidden lg:block">
          <ArtConnector travel travelDelayMs={400} className="-mx-px w-[calc(100%+2px)]" />
        </div>

        <PropertyNode />

        <div className="relative z-0 hidden lg:block">
          <ArtConnector travel travelDelayMs={900} className="-mx-px w-[calc(100%+2px)]" />
        </div>

        <div className="relative z-[1] min-w-0">
          <DashboardPanel />
        </div>
      </div>

      <ArtChip className="-top-2 end-[6%] hidden sm:block" delayMs={900} floatDelayMs={300}>
        <div className="flex items-center gap-2">
          <Cookie className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <span className="text-[11.5px] font-medium text-foreground">{t('0 cookies')}</span>
        </div>
      </ArtChip>
      <ArtChip className="-bottom-3 start-[30%] hidden lg:block" delayMs={1050} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <Bot className="size-3.5 text-amber-600 dark:text-amber-400" aria-hidden />
          <span className="text-[11px] text-muted-foreground">{t('AI agents labeled by name')}</span>
        </div>
      </ArtChip>
    </div>
  )
}
