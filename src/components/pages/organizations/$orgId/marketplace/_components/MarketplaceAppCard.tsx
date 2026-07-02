import type { MarketplaceApp } from '@/lib/marketplace/types'
import { MARKETPLACE_CATEGORY_ICONS } from '@/lib/marketplace/types'
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

  return (
    <ResourceCard
      title={app.name}
      titleAccessory={<MarketplaceAppBadges app={app} />}
      subtitle={app.shortDescription}
      icon={CategoryIcon}
      onClick={onClick}
      statusLabel={app.isOwned ? t(statusLabel(app.status)) : undefined}
      status={app.isOwned ? statusVariant(app.status) : undefined}
    />
  )
}
