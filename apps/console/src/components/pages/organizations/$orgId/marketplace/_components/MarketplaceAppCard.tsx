import type { MarketplaceApp } from '@/lib/marketplace/types'
import { MARKETPLACE_CATEGORY_ICONS } from '@/lib/marketplace/types'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { ResourceCard } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { MarketplaceAppBadges } from './MarketplaceAppBadges'
import { MarketplaceAppLogo } from './MarketplaceAppLogo'

type MarketplaceAppCardProps = {
  app: MarketplaceApp
  onClick?: () => void
}

export function MarketplaceAppCard({ app, onClick }: MarketplaceAppCardProps) {
  const CategoryIcon = MARKETPLACE_CATEGORY_ICONS[app.category]
  const logoUrl = resolveAppLogoDisplayUrl(app.logoUri, {
    width: 80,
    height: 80,
  })

  return (
    <ResourceCard
      title={app.name}
      titleAccessory={<MarketplaceAppBadges app={app} />}
      subtitle={app.shortDescription}
      icon={logoUrl ? undefined : CategoryIcon}
      customIcon={
        logoUrl ? (
          <MarketplaceAppLogo src={logoUrl} monochrome={app.isOfficial} />
        ) : undefined
      }
      iconColor={logoUrl ? 'bg-transparent p-0' : undefined}
      onClick={onClick}
    />
  )
}
