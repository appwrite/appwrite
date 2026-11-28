import { Check, ChevronLeft, ExternalLink, Layers, VolumeX } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  BeepCard,
  BeepFiringPill,
  BeepPhone,
  BeepSeverityPill,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { MonitorsUsageChart } from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'

/** Outbound bandwidth per hour around the alert, in MB, against a 5 GB threshold scaled to 280. */
const AROUND = [118, 132, 150, 176, 205, 248, 291, 336, 372, 391, 384, 362]

const DETAILS = [
  { label: 'Project', value: 'storefront-prod', mono: true },
  { label: 'Region', value: 'Frankfurt', mono: false },
  { label: 'Started', value: '4 min ago', mono: false },
  { label: 'Announced', value: 'Once', mono: false },
] as const

/** One alert opened from its push: the reading against the threshold, the curve around it, and what to do next. */
export function BeepAlertVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto grid w-full max-w-[560px] items-center gap-5 py-4 sm:grid-cols-[284px_minmax(0,1fr)]">
      <div className="product-hero-rise mx-auto" style={riseStyle(40)}>
        <BeepPhone>
          <div className="flex items-center px-3.5 pt-1">
            <span className="flex items-center gap-0.5 rounded-full border border-border bg-background px-2 py-1 text-[10px] font-medium text-foreground dark:bg-card">
              <ChevronLeft className="size-3" aria-hidden />
              {t('Alerts')}
            </span>
          </div>
          <div className="px-3.5 pt-3">
            <div className="flex gap-1">
              <BeepFiringPill />
              <BeepSeverityPill severity="critical" />
            </div>
            <p className="mt-1.5 font-aeonik-pro text-[23px] leading-none tracking-tight text-foreground">
              {t('Egress spike')}
            </p>
            <p dir="ltr" className="mt-1 text-[10px] text-muted-foreground">
              storefront-prod
            </p>
            <span className="mt-3 flex items-center justify-center gap-1.5 rounded-xl bg-foreground py-2 text-[11px] font-semibold text-background">
              <Check className="size-3.5" aria-hidden />
              {t('Acknowledge')}
            </span>
          </div>
          <div className="mt-3 space-y-2.5 px-3.5">
            <BeepCard>
              <p className="text-[10px] text-muted-foreground">
                {t('Now · Outbound bandwidth')}
              </p>
              <p
                dir="ltr"
                className="mt-0.5 text-[26px] font-semibold leading-none tracking-tight text-rose-600 dark:text-rose-400"
              >
                6.2 GB
              </p>
              <p className="mt-1 flex items-center justify-between text-[9.5px] text-muted-foreground">
                {t('> 5 GB threshold · peak 6.9 GB')}
                <span className="font-medium text-[var(--tone-ink)]">
                  {t('Adjust')}
                </span>
              </p>
              <span
                className="mt-2 block h-1 overflow-hidden rounded-full bg-muted"
                aria-hidden
              >
                <span className="block h-full w-full rounded-full bg-rose-500" />
              </span>
            </BeepCard>
            <BeepCard>
              <p className="text-[10px] text-muted-foreground">
                {t('Around this alert, per hour')}
              </p>
              <MonitorsUsageChart
                className="mt-2 text-foreground"
                values={AROUND}
                threshold={280}
                max={420}
                height={84}
                markLast={false}
              />
              <p
                dir="ltr"
                className="mt-1 flex justify-between text-[8.5px] text-muted-foreground"
              >
                <span>10 AM</span>
                <span>4 PM</span>
              </p>
            </BeepCard>
          </div>
        </BeepPhone>
      </div>

      <div className="hidden min-w-0 space-y-3 sm:block">
        <ArtPanel delayMs={500} innerClassName="px-3.5 py-3">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Details')}
          </p>
          <dl className="mt-2 space-y-1.5 text-[11.5px]">
            {DETAILS.map((row) => (
              <div key={row.label} className="flex justify-between gap-3">
                <dt className="text-muted-foreground">{t(row.label)}</dt>
                <dd
                  dir={row.mono ? 'ltr' : undefined}
                  className={
                    row.mono
                      ? 'font-mono text-[11px] text-foreground'
                      : 'text-foreground'
                  }
                >
                  {row.mono ? row.value : t(row.value)}
                </dd>
              </div>
            ))}
          </dl>
        </ArtPanel>
        <ArtPanel delayMs={700} innerClassName="p-1.5">
          {[
            { label: 'View project', icon: Layers },
            { label: 'Open in console', icon: ExternalLink },
            { label: 'Mute this monitor', icon: VolumeX },
          ].map((action) => {
            const Icon = action.icon
            return (
              <span
                key={action.label}
                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-[11.5px] text-foreground"
              >
                <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                {t(action.label)}
              </span>
            )
          })}
        </ArtPanel>
      </div>
    </div>
  )
}
