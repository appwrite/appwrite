import { MarketplaceAppLogo } from '@/components/pages/organizations/$orgId/marketplace/_components/MarketplaceAppLogo'
import { getOAuth2ProviderIconPath } from '@/lib/oauth2/provider-display'
type OAuth2ProviderLogoProps = {
  providerId: string
  size?: 'sm' | 'md' | 'lg' | 'consent' | 'xl'
  className?: string
}

export function OAuth2ProviderLogo({
  providerId,
  size = 'sm',
  className,
}: OAuth2ProviderLogoProps) {
  return (
    <MarketplaceAppLogo
      src={getOAuth2ProviderIconPath(providerId)}
      size={size}
      className={className}
      fallbackSrc="/icons/empty.svg"
    />
  )
}
