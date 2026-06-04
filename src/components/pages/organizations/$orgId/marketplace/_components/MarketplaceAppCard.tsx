import type { MarketplaceApp } from '@/lib/marketplace/mock-data'
import { MARKETPLACE_CATEGORY_ICONS } from '@/lib/marketplace/mock-data'
import { ResourceCard } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { MarketplaceAppBadges } from './MarketplaceAppBadges'

type MarketplaceAppCardProps = {
  app: MarketplaceApp
  onClick?: () => void
}

function statusVariant(
  status: MarketplaceApp['status'],
): 'success' | 'warning' | 'info' {
  if (status === 'published') return 'success'
  if (status === 'pending') return 'warning'
  return 'info'
}

function statusLabel(status: MarketplaceApp['status']): string {
  if (status === 'published') return 'Published'
  if (status === 'pending') return 'Pending review'
  return 'Draft'
}

export function MarketplaceAppCard({ app, onClick }: MarketplaceAppCardProps) {
  const CategoryIcon = MARKETPLACE_CATEGORY_ICONS[app.category]

  return (
    <ResourceCard
      title={app.name}
      titleAccessory={<MarketplaceAppBadges app={app} />}
      subtitle={app.shortDescription}
      icon={CategoryIcon}
      onClick={onClick}
      statusLabel={app.isOwned ? statusLabel(app.status) : undefined}
      status={app.isOwned ? statusVariant(app.status) : undefined}
    />
  )
}
