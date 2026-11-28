import {
  Bell,
  Gauge,
  Mail,
  RotateCcw,
  Smartphone,
  Webhook,
  type LucideIcon,
} from 'lucide-react'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import {
  MONITORS_EGRESS_DAY,
  MonitorsSeverityPill,
  MonitorsStatusPill,
  MonitorsUsageChart,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'

/** Where the alert goes the moment the monitor fires. */
const DELIVERIES: {
  icon: LucideIcon
  label: string
  detail: string
  delayMs: number
}[] = [
  {
    icon: Mail,
    label: 'Email',
    detail: '[Firing] Egress spike · storefront-prod',
    delayMs: 900,
  },
  {
    icon: Bell,
    label: 'Console',
    detail: 'Egress spike is firing',
    delayMs: 1020,
  },
  {
    icon: Smartphone,
    label: 'Appwrite Beep',
    detail: '[Critical] Egress spike on storefront-prod',
    delayMs: 1140,
  },
  {
    icon: Webhook,
    label: 'Webhook',
    detail: 'monitors.*.alerts.*.create',
    delayMs: 1260,
  },
]

function MonitorCard() {
  const t = useT()
  return (
    <ArtPanel
      delayMs={0}
      innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] p-3.5 dark:border-[rgb(var(--tone-rgb)/0.4)]"
    >
      <div className="flex items-center gap-2.5">
        <span className="flex size-9 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
          <Gauge className="size-[18px]" aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="truncate text-[13px] font-semibold text-foreground">
            {t('Egress spike')}
          </p>
          <p
            dir="ltr"
            className="truncate font-mono text-[10.5px] text-muted-foreground"
          >
            storefront-prod
          </p>
        </div>
      </div>
      <dl className="mt-3 space-y-1.5 border-t border-border/70 pt-3 text-[11px]">
        <div className="flex justify-between gap-2">
          <dt className="text-muted-foreground">{t('Metric')}</dt>
          <dd className="truncate text-foreground">
            {t('Outbound bandwidth')}
          </dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt className="text-muted-foreground">{t('Fires when')}</dt>
          <dd dir="ltr" className="font-mono text-[10.5px] text-foreground">
            &gt; 5 GB / 1 h
          </dd>
        </div>
        <div className="flex items-center justify-between gap-2">
          <dt className="text-muted-foreground">{t('Severity')}</dt>
          <dd>
            <MonitorsSeverityPill severity="critical" />
          </dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt className="text-muted-foreground">{t('Notifies')}</dt>
          <dd className="text-foreground">{t('Owners')}</dd>
        </div>
      </dl>
    </ArtPanel>
  )
}

function ChartPanel() {
  const t = useT()
  return (
    <ArtPanel
      delayMs={420}
      innerClassName="product-tone-shadow px-3.5 pb-3 pt-3"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Outbound bandwidth')}
          </p>
          <p className="mt-1 flex items-baseline gap-1.5">
            <span
              dir="ltr"
              className="font-aeonik-pro text-[22px] leading-none tracking-tight text-rose-600 dark:text-rose-400"
            >
              6.2 GB
            </span>
            <span className="text-[10.5px] text-muted-foreground">
              {t('1.2× the threshold')}
            </span>
          </p>
        </div>
        <MonitorsStatusPill status="firing" />
      </div>
      <MonitorsUsageChart
        className="mt-5 text-foreground"
        values={MONITORS_EGRESS_DAY}
        threshold={5}
        max={8}
        height={104}
        thresholdLabel={<span dir="ltr">5 GB</span>}
      />
      <p
        dir="ltr"
        className="mt-2 flex justify-between text-[9.5px] text-muted-foreground"
      >
        <span>12 AM</span>
        <span>12 PM</span>
        <span>11 PM</span>
      </p>
    </ArtPanel>
  )
}

/**
 * A usage monitor on the start side, its metric crossing the dashed threshold
 * in the middle, and the alert going out on the end side: email, the Console,
 * Appwrite Beep, and a webhook.
 */
export function MonitorsHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto max-w-5xl py-4 text-start">
      <div className="grid items-center gap-6 lg:grid-cols-[minmax(0,0.82fr)_2.5rem_minmax(0,1.25fr)_2.5rem_minmax(0,1fr)] lg:gap-0">
        <div className="relative z-[1] mx-auto w-full max-w-[260px] lg:max-w-none">
          <MonitorCard />
        </div>

        <div className="relative z-0 hidden lg:block">
          <ArtConnector
            travel
            travelDelayMs={300}
            className="-mx-px w-[calc(100%+2px)]"
          />
        </div>

        <div className="relative z-[1] min-w-0">
          <ChartPanel />
        </div>

        <div className="relative z-0 hidden lg:block">
          <ArtConnector
            travel
            travelDelayMs={800}
            className="-mx-px w-[calc(100%+2px)]"
          />
        </div>

        <div className="relative z-[1] min-w-0 space-y-2">
          {DELIVERIES.map((item, index) => (
            <ArtPanel
              key={item.label}
              delayMs={item.delayMs}
              float
              floatDelayMs={index * 420}
              innerClassName="flex items-center gap-2.5 px-3 py-2"
            >
              <ArtIconBadge
                icon={item.icon}
                tone={index === 2 ? 'primary' : 'neutral'}
              />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[12px] font-semibold text-foreground">
                  {t(item.label)}
                </span>
                <span
                  dir="ltr"
                  className="block truncate font-mono text-[10px] text-muted-foreground"
                >
                  {item.detail}
                </span>
              </span>
            </ArtPanel>
          ))}
        </div>
      </div>

      <ArtChip
        className="-bottom-3 start-[30%] hidden lg:block"
        delayMs={1500}
        floatDelayMs={600}
      >
        <span className="flex items-center gap-2 text-[11px] text-muted-foreground">
          <RotateCcw
            className="size-3.5 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
          {t('Resolves on its own when usage recovers')}
        </span>
      </ArtChip>
    </div>
  )
}
