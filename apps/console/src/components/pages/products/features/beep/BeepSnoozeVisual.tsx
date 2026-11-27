import { BellOff, Check } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import {
  BeepCard,
  BeepPhone,
  BeepScreenHeader,
  BeepSheet,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const LENGTHS = ['30 minutes', '1 hour', '4 hours', '24 hours'] as const

/** A burst of pushes from one incident, the offer to snooze on Home, and the snooze sheet it opens. */
export function BeepSnoozeVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone>
          <BeepScreenHeader title="Home" />
          <div className="mt-3 px-3.5">
            <BeepCard className="border-[rgb(var(--tone-rgb)/0.45)]">
              <p className="flex items-center gap-1.5 text-[11.5px] font-semibold text-foreground">
                <BellOff
                  className="size-3.5 text-[var(--tone-ink)]"
                  aria-hidden
                />
                {t('7 notifications in 30 minutes')}
              </p>
              <p className="mt-1 text-[10px] leading-[14px] text-muted-foreground">
                {t(
                  'Snooze notifications while you look into it. Alerts keep showing here and in the console.',
                )}
              </p>
              <span className="mt-2.5 block rounded-full bg-[var(--brand-cta)] py-1.5 text-center text-[11px] font-semibold text-white">
                {t('Snooze notifications')}
              </span>
            </BeepCard>
          </div>

          <div
            className="absolute inset-0 z-[2] bg-black/35 dark:bg-black/50"
            aria-hidden
          />
          <BeepSheet className="product-hero-rise z-[4]" style={riseStyle(420)}>
            <p className="text-[13px] font-semibold text-foreground">
              {t('Snooze notifications')}
            </p>
            <div className="mt-2.5 grid grid-cols-2 gap-0.5 rounded-full bg-muted p-0.5 text-center text-[10px] font-medium">
              <span className="rounded-full bg-background py-1 text-foreground shadow-sm dark:bg-card">
                Acme Inc
              </span>
              <span className="py-1 text-muted-foreground">
                {t('All organizations')}
              </span>
            </div>
            <div className="mt-2.5 divide-y divide-border/70 overflow-hidden rounded-2xl border border-border/80">
              {LENGTHS.map((length, index) => (
                <span
                  key={length}
                  className={cn(
                    'flex items-center justify-between px-3 py-2 text-[11px]',
                    index === 1
                      ? 'font-semibold text-foreground'
                      : 'text-foreground',
                  )}
                >
                  {t(length)}
                  {index === 1 ? (
                    <Check
                      className="size-3.5 text-[var(--tone-ink)]"
                      aria-hidden
                    />
                  ) : null}
                </span>
              ))}
            </div>
            <p className="mt-2 text-[9px] leading-[13px] text-muted-foreground">
              {t(
                'No notifications from Acme Inc until then, critical alerts included, on all your devices. Alerts still fire and show here, in the console and in email.',
              )}
            </p>
          </BeepSheet>
        </BeepPhone>
      </div>
    </div>
  )
}
