import { Check } from 'lucide-react'
import type { CSSProperties } from 'react'
import { Switch } from '@/components/ui/switch'
import { ArtChip, ArtPanel, floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ENABLED_PROVIDERS = [
  { id: 'google', name: 'Google', icon: '/icons/google.svg' },
  { id: 'github', name: 'GitHub', icon: '/icons/github.svg' },
  { id: 'apple', name: 'Apple', icon: '/icons/apple.svg' },
] as const

/** Scattered around the panel; `fade` dims tiles further from the center. */
const SCATTERED_PROVIDERS: { id: string; icon: string; className: string; fade?: boolean }[] = [
  { id: 'discord', icon: '/icons/discord-simple.svg', className: 'start-[4%] top-[6%]' },
  { id: 'microsoft', icon: '/icons/microsoft.svg', className: 'start-[22%] top-[0%]', fade: true },
  { id: 'gitlab', icon: '/icons/gitlab.svg', className: 'end-[20%] top-[2%]' },
  { id: 'slack', icon: '/icons/slack.svg', className: 'end-[2%] top-[18%]', fade: true },
  { id: 'linkedin', icon: '/icons/linkedin.svg', className: 'start-[0%] top-[46%]', fade: true },
  { id: 'spotify', icon: '/icons/spotify.svg', className: 'end-[0%] top-[56%]' },
  { id: 'twitch', icon: '/icons/twitch.svg', className: 'start-[8%] bottom-[6%]' },
  { id: 'okta', icon: '/icons/okta.svg', className: 'end-[12%] bottom-[2%]', fade: true },
  { id: 'facebook', icon: '/icons/facebook.svg', className: 'start-[28%] bottom-[0%]', fade: true },
]

export function AuthOAuthVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[380px] w-full max-w-[520px]">
      {SCATTERED_PROVIDERS.map((provider, index) => (
        <span
          key={provider.id}
          className={cn('product-hero-rise absolute', provider.className)}
          style={riseStyle(200 + index * 70)}
          aria-hidden
        >
          <span
            className={cn(
              'product-hero-float flex size-11 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card',
              provider.fade && 'opacity-55',
            )}
            style={floatStyle(index * 380) as CSSProperties}
          >
            <ProductFeaturePublicIcon src={provider.icon} className="size-[18px]" />
          </span>
        </span>
      ))}

      <ArtPanel
        className="absolute left-1/2 top-1/2 z-[1] w-[min(260px,70%)] -translate-x-1/2 -translate-y-1/2"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={60}
      >
        <p className="text-[12px] font-semibold text-foreground">{t('Social providers')}</p>
        <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">
          {t('Enable OAuth 2 sign-in for external accounts.')}
        </p>
        <div className="mt-3 space-y-1.5">
          {ENABLED_PROVIDERS.map((provider, index) => (
            <div
              key={provider.id}
              className="product-hero-rise flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/30 px-2.5 py-2"
              style={riseStyle(350 + index * 120)}
            >
              <span className="flex items-center gap-2">
                <ProductFeaturePublicIcon src={provider.icon} className="size-3.5" />
                <span className="text-[12px] font-medium text-foreground">{provider.name}</span>
              </span>
              <Switch checked disabled className="scale-90 data-[state=checked]:bg-foreground/80" aria-hidden />
            </div>
          ))}
        </div>
      </ArtPanel>

      <ArtChip className="bottom-[16%] end-[24%] sm:end-[26%]" delayMs={900} floatDelayMs={600}>
        <div className="flex items-center gap-1.5">
          <span className="flex size-4 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
            <Check className="size-2.5" strokeWidth={3} aria-hidden />
          </span>
          <span className="text-[11px] font-medium text-foreground">{t('30+ providers')}</span>
        </div>
      </ArtChip>
    </div>
  )
}
