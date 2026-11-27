import { BellRing, MoonStar } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  BeepAppIcon,
  BeepPhone,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'

/** The three steps on Beep's Android "Critical alerts" screen. */
const STEPS = [
  {
    title: 'Set monitors to Critical',
    detail:
      'In the console, as a usage monitor’s severity or on a budget threshold.',
  },
  {
    title: 'Let them override Do Not Disturb',
    detail: 'Android asks you to switch it on for the channel.',
  },
  { title: 'Set the volume', detail: 'They ring at your alarm volume.' },
] as const

/** An Android phone ringing for a Critical monitor through Do Not Disturb, beside the steps that set it up. */
export function BeepCriticalVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto grid w-full max-w-[580px] items-center gap-6 py-4 sm:grid-cols-[284px_minmax(0,1fr)]">
      <div className="product-hero-rise mx-auto" style={riseStyle(40)}>
        <BeepPhone
          device="android"
          screenClassName="bg-[radial-gradient(110%_55%_at_50%_42%,rgb(244_63_94/0.2),transparent_70%)]"
        >
          <div className="flex h-full flex-col items-center px-4 pb-6 pt-2 text-center">
            <span className="flex items-center gap-1 rounded-full bg-muted/80 px-2 py-0.5 text-[9.5px] font-medium text-muted-foreground">
              <MoonStar className="size-3" aria-hidden />
              {t('Do Not Disturb')}
            </span>
            <div className="relative mt-14 flex size-24 items-center justify-center">
              <span
                className="absolute inset-0 animate-ping rounded-full bg-rose-500/20 motion-reduce:animate-none"
                aria-hidden
              />
              <span
                className="absolute inset-3 rounded-full bg-rose-500/15"
                aria-hidden
              />
              <span className="relative flex size-14 items-center justify-center rounded-2xl bg-rose-500 text-white shadow-[0_12px_30px_-8px_rgb(244_63_94/0.7)]">
                <BellRing className="size-7" aria-hidden />
              </span>
            </div>
            <p className="mt-6 flex items-center gap-1.5 text-[10px] font-medium text-muted-foreground">
              <BeepAppIcon className="size-4 rounded-[5px]" />
              {t('Critical alerts')}
            </p>
            <p
              dir="ltr"
              className="mt-2 font-aeonik-pro text-[21px] leading-tight tracking-tight text-foreground"
            >
              [Critical] Egress spike on storefront-prod
            </p>
            <p dir="ltr" className="mt-1.5 text-[11px] text-muted-foreground">
              6.2 GB &gt; 5 GB · storefront-prod · Acme Inc
            </p>
            <div className="mt-auto grid w-full grid-cols-2 gap-2">
              <span className="rounded-full border border-border bg-background py-2 text-[11px] font-medium text-foreground dark:bg-card">
                {t('Open alert')}
              </span>
              <span className="rounded-full bg-rose-500 py-2 text-[11px] font-semibold text-white">
                {t('Acknowledge')}
              </span>
            </div>
          </div>
        </BeepPhone>
      </div>

      <ArtPanel delayMs={500} innerClassName="p-4">
        <p className="font-aeonik-pro text-[19px] leading-tight text-foreground">
          {t('Ring for what matters')}
          <span className="text-[var(--brand-cta)]">_</span>
        </p>
        <p className="mt-1.5 text-[11.5px] leading-[18px] text-muted-foreground">
          {t(
            'Monitors your team sets to Critical ring this phone like an alarm, even on silent and in Do Not Disturb. Everything else stays a normal notification.',
          )}
        </p>
        <ol className="mt-3 space-y-2.5 border-t border-border/70 pt-3">
          {STEPS.map((step, index) => (
            <li
              key={step.title}
              className="product-hero-rise flex gap-2.5"
              style={riseStyle(700 + index * 140)}
            >
              <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.14)] font-mono text-[10px] text-[var(--tone-ink)]">
                {index + 1}
              </span>
              <span className="min-w-0">
                <span className="block text-[12px] font-medium text-foreground">
                  {t(step.title)}
                </span>
                <span className="block text-[11px] leading-[16px] text-muted-foreground">
                  {t(step.detail)}
                </span>
              </span>
            </li>
          ))}
        </ol>
      </ArtPanel>
    </div>
  )
}
