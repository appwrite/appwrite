import type { MarketplaceApp } from '@/lib/marketplace/types'
import { MARKETPLACE_CATEGORY_ICONS } from '@/lib/marketplace/types'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { ResourceCard } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { MarketplaceAppBadges } from './MarketplaceAppBadges'
import { useT } from '@/lib/i18n/translate'

type MarketplaceAppCardProps = {
  app: MarketplaceApp
  onClick?: () => void
}

function statusVariant(
  status: MarketplaceApp['status'],
): 'success' | 'info' {
  if (status === 'published') return 'success'
  return 'info'
}

function statusLabel(status: MarketplaceApp['status']): string {
  if (status === 'published') return 'Published'
  return 'Draft'
}

export function MarketplaceAppCard({ app, onClick }: MarketplaceAppCardProps) {
  const t = useT()
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
          <img
            src={logoUrl}
            alt=""
            className="h-10 w-10 rounded-lg object-cover"
          />
        ) : undefined
      }
      iconColor={logoUrl ? 'bg-transparent p-0' : undefined}
      onClick={onClick}
      statusLabel={app.isOwned ? t(statusLabel(app.status)) : undefined}
      status={app.isOwned ? statusVariant(app.status) : undefined}
    />
  )
}
