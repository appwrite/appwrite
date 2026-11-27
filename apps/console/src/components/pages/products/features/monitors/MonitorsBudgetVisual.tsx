import { Mail, Plus } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { BeepPush } from '@/components/pages/products/features/beep/BeepArtParts'
import {
  MonitorsCaption,
  MonitorsField,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const THRESHOLDS = [50, 75, 90] as const
const USED = 82

/** Billing settings: the budget cap, the share of it used this cycle, and the alerts on the way up. */
export function MonitorsBudgetVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] flex-col gap-3 py-6">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-4">
        <div className="flex items-baseline justify-between gap-3">
          <MonitorsCaption>{t('Budget used this cycle')}</MonitorsCaption>
          <span dir="ltr" className="text-[11px] text-muted-foreground">
            $164 / $200
          </span>
        </div>
        <p
          dir="ltr"
          className="mt-1 font-aeonik-pro text-[26px] leading-none tracking-tight text-foreground"
        >
          {USED}%
        </p>
        <div className="relative mt-5 h-2 rounded-full bg-muted" aria-hidden>
          <span
            className="absolute inset-y-0 start-0 rounded-full bg-[var(--tone-ink)]"
            style={{ width: `${USED}%` }}
          />
          {[...THRESHOLDS, 100].map((threshold) => (
            <span
              key={threshold}
              className="absolute -top-1 h-4 w-px bg-foreground/40"
              style={{ insetInlineStart: `${threshold}%` }}
            />
          ))}
        </div>
        <div
          dir="ltr"
          className="relative mt-1.5 h-3 text-[9.5px] text-muted-foreground"
        >
          {[...THRESHOLDS, 100].map((threshold) => (
            <span
              key={threshold}
              className={cn(
                'absolute -translate-x-1/2',
                threshold === 100 && '-translate-x-full',
              )}
              style={{ left: `${threshold}%` }}
            >
              {threshold === 100 ? t('Cap') : `${threshold}%`}
            </span>
          ))}
        </div>

        <MonitorsCaption className="mt-5">
          {t('Billing alerts')}
        </MonitorsCaption>
        <ul className="mt-2 space-y-1.5">
          {THRESHOLDS.map((threshold, index) => (
            <li
              key={threshold}
              className="product-hero-rise flex flex-wrap items-center gap-1.5 text-[11px] text-foreground"
              style={riseStyle(300 + index * 100)}
            >
              {t('Alert when spending reaches')}
              <MonitorsField
                className="w-[4.25rem]"
                select
                value={<span dir="ltr">{threshold}%</span>}
              />
              {t('of budget')}
            </li>
          ))}
        </ul>
        <span className="mt-2.5 inline-flex items-center gap-1 rounded-md border border-dashed border-border px-2 py-1 text-[10.5px] text-muted-foreground">
          <Plus className="size-3" aria-hidden />
          {t('Add alert')}
        </span>
      </ArtPanel>

      <ArtPanel
        className="w-full sm:ms-auto sm:w-[82%]"
        delayMs={600}
        float
        floatDelayMs={200}
        innerClassName="flex items-center gap-2.5 px-3 py-2.5"
      >
        <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
          <Mail className="size-3.5" aria-hidden />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-[11.5px] font-semibold text-foreground">
            {t('Over 75% of budget used · Acme Inc')}
          </span>
          <span className="block text-[10px] text-muted-foreground">
            {t('Email to Owners')}
          </span>
        </span>
        <span className="shrink-0 rounded-md border border-border px-2 py-1 text-[10.5px] text-foreground">
          {t('Update budget cap')}
        </span>
      </ArtPanel>

      <BeepPush
        className="product-hero-rise w-full border-border bg-background shadow-[0_16px_40px_-20px_rgb(0_0_0/0.35)] sm:w-[80%] dark:bg-card"
        style={riseStyle(760)}
        title={<span dir="ltr">[Warning] Budget at 75% on Acme Inc</span>}
        body={<span dir="ltr">82% &gt; 75% · Organization budget</span>}
        time="just now"
      />
    </div>
  )
}
