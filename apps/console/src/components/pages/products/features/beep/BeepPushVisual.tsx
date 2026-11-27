import { BellOff, Check, VolumeX, type LucideIcon } from 'lucide-react'
import {
  ArtChip,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  BeepPhone,
  BeepPush,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'

const ACTIONS: { label: string; icon: LucideIcon }[] = [
  { label: 'Acknowledge', icon: Check },
  { label: 'Snooze 1 hour', icon: BellOff },
  { label: 'Mute this monitor', icon: VolumeX },
]

/** A firing push held open on the lock screen, with the buttons that work without opening the app. */
export function BeepPushVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone screenClassName="bg-[radial-gradient(120%_70%_at_50%_10%,rgb(var(--tone-rgb)/0.14),transparent_70%)]">
          <div className="flex h-full flex-col px-3 pt-3">
            <div className="opacity-40 blur-[1.5px]" aria-hidden>
              <p className="text-center text-[11px] font-medium text-muted-foreground">
                {t('Thursday, December 3')}
              </p>
              <p
                dir="ltr"
                className="text-center font-aeonik-pro text-[64px] leading-none tracking-tight text-foreground"
              >
                9:41
              </p>
            </div>
            <BeepPush
              className="product-hero-rise mt-8 scale-[1.02] border-[rgb(var(--tone-rgb)/0.35)] py-3"
              style={riseStyle(260)}
              title={
                <span dir="ltr">[Warning] Read surge on storefront-prod</span>
              }
              body={
                <span dir="ltr">
                  1.2M &gt; 1M · storefront-prod · Acme Inc · Frankfurt
                </span>
              }
              time="just now"
            />
            <div
              className="product-hero-rise mt-2.5 overflow-hidden rounded-[1.1rem] border border-border/70 bg-background/90 shadow-[0_10px_30px_-18px_rgb(0_0_0/0.45)] backdrop-blur dark:bg-card/90"
              style={riseStyle(520)}
            >
              {ACTIONS.map((action, index) => {
                const Icon = action.icon
                return (
                  <span
                    key={action.label}
                    className={
                      index === 0
                        ? 'flex items-center justify-between border-b border-border/70 bg-[rgb(var(--tone-rgb)/0.1)] px-3.5 py-2.5 text-[11.5px] font-semibold text-foreground'
                        : 'flex items-center justify-between border-b border-border/70 px-3.5 py-2.5 text-[11.5px] text-foreground last:border-b-0'
                    }
                  >
                    {t(action.label)}
                    <Icon
                      className="size-3.5 text-muted-foreground"
                      aria-hidden
                    />
                  </span>
                )
              })}
            </div>
          </div>
        </BeepPhone>
      </div>

      <ArtChip
        className="bottom-[14%] end-0 hidden sm:block"
        delayMs={1000}
        floatDelayMs={300}
      >
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Check
            className="size-3 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
          {t('Your team sees you have it')}
        </span>
      </ArtChip>
    </div>
  )
}
