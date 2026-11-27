import { Check, Gauge } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  MonitorsCaption,
  MonitorsSeverityPill,
  MonitorsStatusPill,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TILES = [
  {
    label: 'Latest reading',
    value: '6.2 GB',
    note: '1.2× the threshold',
    alert: true,
  },
  { label: 'Peak', value: '6.9 GB', note: '1.4× the threshold', alert: true },
  { label: 'Firing for', value: '18 min', note: 'Ongoing', alert: false },
  {
    label: 'Notifications',
    value: '1',
    note: 'Email, push, and Console',
    alert: false,
  },
] as const

const TIMELINE = [
  { label: 'Started firing', time: '9:23 AM', state: 'done' },
  { label: 'Acknowledged by Ana Silva', time: '9:41 AM', state: 'done' },
  { label: 'Waiting for recovery', time: '', state: 'pending' },
] as const

/** The alert drawer: how far past the threshold, for how long, who has it, and what happened. */
export function MonitorsAlertVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[460px] py-6">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-0">
        <div className="border-b border-border px-4 py-3">
          <div className="flex items-center gap-1.5">
            <MonitorsStatusPill status="firing" />
            <MonitorsSeverityPill severity="critical" />
          </div>
          <p className="mt-2 font-aeonik-pro text-[20px] leading-none tracking-tight text-foreground">
            {t('Egress spike')}
          </p>
          <p
            dir="ltr"
            className="mt-1 font-mono text-[10.5px] text-muted-foreground"
          >
            storefront-prod · Frankfurt
          </p>
        </div>

        <div className="grid grid-cols-2 gap-px bg-border">
          {TILES.map((tile, index) => (
            <div
              key={tile.label}
              className="product-hero-rise bg-background px-4 py-3 dark:bg-card"
              style={riseStyle(200 + index * 90)}
            >
              <p className="text-[10px] text-muted-foreground">
                {t(tile.label)}
              </p>
              <p
                dir="ltr"
                className={cn(
                  'mt-0.5 text-[18px] font-semibold leading-tight tracking-tight',
                  tile.alert
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-foreground',
                )}
              >
                {tile.value}
              </p>
              <p className="text-[10px] text-muted-foreground">
                {t(tile.note)}
              </p>
            </div>
          ))}
        </div>

        <div className="border-t border-border px-4 py-3">
          <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/[0.06] px-3 py-2">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
              <Check className="size-3" strokeWidth={3} aria-hidden />
            </span>
            <span className="min-w-0 flex-1 text-[11px] text-foreground">
              {t('Acknowledged by Ana Silva')}
            </span>
            <span className="rounded-md border border-border px-2 py-0.5 text-[10.5px] text-muted-foreground">
              {t('Undo')}
            </span>
          </div>

          <dl className="mt-3 space-y-1.5 text-[11px]">
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{t('Condition')}</dt>
              <dd className="truncate text-foreground">
                {t('Outbound bandwidth')} <span dir="ltr">&gt; 5 GB</span>{' '}
                {t('within 1 hour')}
              </dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{t('Watches')}</dt>
              <dd className="text-foreground">{t('The whole project')}</dd>
            </div>
          </dl>

          <MonitorsCaption className="mt-4">{t('Timeline')}</MonitorsCaption>
          <ol className="mt-2">
            {TIMELINE.map((step, index) => (
              <li
                key={step.label}
                className="product-hero-rise relative flex gap-2.5 pb-2.5 last:pb-0"
                style={riseStyle(600 + index * 140)}
              >
                {index < TIMELINE.length - 1 ? (
                  <span
                    className="absolute start-[5px] top-3 h-full w-px bg-border"
                    aria-hidden
                  />
                ) : null}
                <span
                  className={cn(
                    'relative mt-1 size-[11px] shrink-0 rounded-full border-2',
                    step.state === 'done'
                      ? index === 0
                        ? 'border-rose-500 bg-rose-500'
                        : 'border-emerald-500 bg-emerald-500'
                      : 'border-muted-foreground/50 bg-background dark:bg-card',
                  )}
                  aria-hidden
                />
                <span className="flex min-w-0 flex-1 justify-between gap-2 text-[11px]">
                  <span
                    className={
                      step.state === 'done'
                        ? 'text-foreground'
                        : 'text-muted-foreground'
                    }
                  >
                    {t(step.label)}
                  </span>
                  {step.time ? (
                    <span dir="ltr" className="shrink-0 text-muted-foreground">
                      {step.time}
                    </span>
                  ) : null}
                </span>
              </li>
            ))}
          </ol>
        </div>

        <div className="flex justify-end border-t border-border bg-muted/30 px-4 py-2.5">
          <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-[11px] text-foreground dark:bg-card">
            <Gauge className="size-3 text-muted-foreground" aria-hidden />
            {t('View monitor')}
          </span>
        </div>
      </ArtPanel>
    </div>
  )
}
