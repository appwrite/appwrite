import { Badge } from '@/components/ui/badge'
import { MockProviderTile } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const POPULAR_PROVIDERS = [
  { id: 'google', name: 'Google', icon: '/icons/google.svg', enabled: true },
  { id: 'github', name: 'GitHub', icon: '/icons/github.svg', enabled: true },
  { id: 'apple', name: 'Apple', icon: '/icons/apple.svg', enabled: true },
  { id: 'discord', name: 'Discord', icon: '/icons/discord-simple.svg', enabled: false },
  { id: 'microsoft', name: 'Microsoft', icon: '/icons/microsoft.svg', enabled: false },
  { id: 'spotify', name: 'Spotify', icon: '/icons/spotify.svg', enabled: false },
] as const

const MORE_PROVIDERS = [
  { id: 'gitlab', name: 'GitLab', icon: '/icons/gitlab.svg' },
  { id: 'linkedin', name: 'LinkedIn', icon: '/icons/linkedin.svg' },
  { id: 'slack', name: 'Slack', icon: '/icons/slack.svg' },
  { id: 'twitch', name: 'Twitch', icon: '/icons/twitch.svg' },
  { id: 'facebook', name: 'Facebook', icon: '/icons/facebook.svg' },
  { id: 'okta', name: 'Okta', icon: '/icons/okta.svg' },
] as const

export function AuthOAuthVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: 'Overview' },
        { id: 'social', label: 'Social providers', active: true },
        { id: 'settings', label: 'Settings' },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">
              {t('Social providers')}
            </p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Enable OAuth 2 sign-in for external accounts.')}
            </p>
          </div>
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('30+ providers')}
          </Badge>
        </div>

        <div>
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Popular')}
          </p>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {POPULAR_PROVIDERS.map((provider, index) => (
              <MockProviderTile
                key={provider.id}
                name={provider.name}
                iconSrc={provider.icon}
                enabled={provider.enabled}
                style={{ transitionDelay: `${index * 60}ms` }}
              />
            ))}
          </div>
        </div>

        <div>
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('More providers')}
          </p>
          <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-6">
            {MORE_PROVIDERS.map((provider) => (
              <div
                key={provider.id}
                className="flex flex-col items-center gap-1 rounded-lg border border-border bg-background/80 px-1.5 py-2"
              >
                <ProductFeaturePublicIcon src={provider.icon} inactive />
                <span className="truncate text-[9px] text-muted-foreground">{provider.name}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/20 px-3 py-2.5 text-center">
          <p className="text-[11px] text-muted-foreground">
            {t('One-click signup via GitHub, Google, Apple, and more.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
