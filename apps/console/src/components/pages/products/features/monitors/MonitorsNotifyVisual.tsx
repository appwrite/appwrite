import { Bell, Check } from 'lucide-react'
import { AppwriteMark } from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { BeepPush } from '@/components/pages/products/features/beep/BeepArtParts'
import { MonitorsStatusPill } from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'

/** One alert, three places: the email, the Console's notifications, and a push from Appwrite Beep. */
export function MonitorsNotifyVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] flex-col gap-3 py-6">
      <ArtPanel
        className="w-full sm:w-[90%]"
        delayMs={40}
        innerClassName="product-tone-shadow p-0"
      >
        <div className="flex items-center gap-2.5 border-b border-border px-4 py-2.5">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-full border border-border bg-background dark:bg-card">
            <AppwriteMark className="size-3.5" />
          </span>
          <span className="min-w-0 flex-1">
            <span
              dir="ltr"
              className="block truncate text-[11.5px] font-semibold text-foreground"
            >
              [Firing] Egress spike · storefront-prod
            </span>
            <span className="block text-[10px] text-muted-foreground">
              Appwrite · 9:23 AM
            </span>
          </span>
        </div>
        <div className="px-4 py-3.5">
          <p className="font-aeonik-pro text-[17px] leading-tight tracking-tight text-foreground">
            {t('Outbound bandwidth is above 5 GB')}
          </p>
          <p className="mt-1.5 text-[11px] leading-[16px] text-muted-foreground">
            {t(
              'Outbound bandwidth reached 6.2 GB in the last hour, 1.2× the threshold.',
            )}
          </p>
          <div className="mt-3 grid grid-cols-2 gap-2">
            <div className="rounded-lg border border-border px-3 py-2">
              <p className="text-[10px] text-muted-foreground">{t('Value')}</p>
              <p
                dir="ltr"
                className="text-[15px] font-semibold text-rose-600 dark:text-rose-400"
              >
                6.2 GB
              </p>
              <p className="text-[9.5px] text-muted-foreground">
                {t('Summed over 1 hour')}
              </p>
            </div>
            <div className="rounded-lg border border-border px-3 py-2">
              <p className="text-[10px] text-muted-foreground">
                {t('Threshold')}
              </p>
              <p
                dir="ltr"
                className="text-[15px] font-semibold text-foreground"
              >
                5 GB
              </p>
              <p dir="ltr" className="text-[9.5px] text-muted-foreground">
                storefront-prod · Frankfurt
              </p>
            </div>
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            <span className="rounded-md bg-foreground px-2.5 py-1 text-[11px] font-medium text-background">
              {t('View alert')}
            </span>
            <span className="rounded-md border border-border px-2.5 py-1 text-[11px] text-foreground">
              {t('Pause this monitor')}
            </span>
          </div>
        </div>
      </ArtPanel>

      <ArtPanel
        className="w-full sm:ms-auto sm:w-[78%]"
        delayMs={420}
        float
        floatDelayMs={200}
        innerClassName="flex items-center gap-2.5 px-3 py-2.5"
      >
        <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
          <Bell className="size-3.5" aria-hidden />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-1.5">
            <span className="truncate text-[11.5px] font-semibold text-foreground">
              {t('Egress spike')}
            </span>
            <MonitorsStatusPill status="firing" />
          </span>
          <span className="block truncate text-[10px] text-muted-foreground">
            {t('Console notification')}
          </span>
        </span>
        <span className="inline-flex shrink-0 items-center gap-1 rounded-md border border-border px-2 py-1 text-[10.5px] text-foreground">
          <Check className="size-3" aria-hidden />
          {t('Acknowledge')}
        </span>
      </ArtPanel>

      <BeepPush
        className="product-hero-rise w-full border-border bg-background shadow-[0_16px_40px_-20px_rgb(0_0_0/0.35)] sm:ms-[6%] sm:w-[80%] dark:bg-card"
        style={riseStyle(640)}
        title={
          <span dir="ltr">[Critical] Egress spike on storefront-prod</span>
        }
        body={
          <span dir="ltr">
            6.2 GB &gt; 5 GB · storefront-prod · Acme Inc · Frankfurt
          </span>
        }
        time="just now"
      />
    </div>
  )
}
