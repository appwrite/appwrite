import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import {
  BEEP_FIRING_ALERTS,
  BeepAlertRow,
  BeepPhone,
  BeepPush,
  BeepScreenHeader,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/**
 * Pushes as Beep sends them: `[Severity] <monitor> on <project>`, then the
 * reading and where it fired. The last one is an Appwrite changelog entry.
 */
const PUSHES = [
  {
    title: '[Critical] Egress spike on storefront-prod',
    body: '6.2 GB > 5 GB · storefront-prod · Acme Inc · Frankfurt',
    time: 'just now',
  },
  {
    title: '[Error] Checkout API health on checkout-api',
    body: 'The HTTP check of api.checkout.example.com/v1/health failed: unexpected status code (503).',
    time: '12m ago',
  },
  {
    title: '[Critical] Budget at 100% on Acme Inc',
    body: '102% > 100% · Organization budget',
    time: '18m ago',
  },
  {
    title: 'Usage monitors for every project',
    body: 'Get notified when a project crosses a usage threshold, by email or on your phone.',
    time: '2h ago',
  },
] as const

const FILTERS = ['Firing', 'Critical', 'Error', 'Warning', 'Info'] as const

/**
 * Two phones: the lock screen with updates from your projects arriving as
 * pushes, and behind it the Alerts tab they open into.
 */
export function BeepHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[640px] w-full max-w-[600px] text-start">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 -z-10 size-[520px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.22),transparent)] blur-2xl"
        aria-hidden
      />

      {/* Behind: the Alerts tab. */}
      <div
        className="product-hero-rise absolute end-0 top-0 hidden origin-top-right scale-[0.9] sm:block"
        style={riseStyle(260)}
      >
        <BeepPhone tab="alerts" alertCount={3}>
          <BeepScreenHeader
            title="Alerts"
            subtitle="3 firing · 2 acknowledged"
          />
          <div className="mt-2.5 flex gap-1 overflow-hidden px-3.5">
            {FILTERS.map((filter, index) => (
              <span
                key={filter}
                className={cn(
                  'shrink-0 rounded-full border px-2 py-0.5 text-[9px] font-medium',
                  index === 0
                    ? 'border-foreground/20 bg-foreground/5 text-foreground'
                    : 'border-border text-muted-foreground',
                )}
              >
                {t(filter)}
              </span>
            ))}
          </div>
          <p className="mt-3 px-4 text-[11px] font-semibold text-foreground">
            {t('Firing')} <span className="text-muted-foreground">3</span>
          </p>
          <div className="mt-1.5 space-y-2 px-3.5">
            {BEEP_FIRING_ALERTS.map((alert) => (
              <BeepAlertRow key={alert.title} alert={alert} />
            ))}
          </div>
        </BeepPhone>
      </div>

      {/* In front: the lock screen. */}
      <div
        className="product-hero-rise absolute left-1/2 top-6 z-[1] -translate-x-1/2 sm:start-[2%] sm:left-auto sm:translate-x-0"
        style={riseStyle(0)}
      >
        <BeepPhone screenClassName="bg-[radial-gradient(120%_60%_at_50%_0%,rgb(var(--tone-rgb)/0.16),transparent_65%)]">
          <div className="flex h-full flex-col px-3 pb-4 pt-3">
            <p className="text-center text-[11px] font-medium text-muted-foreground">
              {t('Thursday, December 3')}
            </p>
            <p
              dir="ltr"
              className="text-center font-aeonik-pro text-[64px] leading-none tracking-tight text-foreground"
            >
              9:41
            </p>
            <div className="mt-7 space-y-2">
              {PUSHES.map((push, index) => (
                <BeepPush
                  key={push.title}
                  className="product-hero-rise"
                  style={riseStyle(420 + index * 200)}
                  title={<span dir="ltr">{push.title}</span>}
                  body={<span dir="ltr">{push.body}</span>}
                  time={push.time}
                />
              ))}
            </div>
            <div
              className="mt-auto flex items-center justify-between px-5"
              aria-hidden
            >
              <span className="size-9 rounded-full bg-foreground/10 backdrop-blur" />
              <span className="h-1 w-24 rounded-full bg-foreground/40" />
              <span className="size-9 rounded-full bg-foreground/10 backdrop-blur" />
            </div>
          </div>
        </BeepPhone>
      </div>
    </div>
  )
}
