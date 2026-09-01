import { useParams } from '@tanstack/react-router'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import { BrandingCard } from '../_components/BrandingCard'
import { MarketplaceUrlCard } from '../_components/MarketplaceUrlCard'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  return (
    <div className="space-y-4">
      <MarketplaceUrlCard orgId={orgId} appId={app.$id} />
      <BrandingCard orgId={orgId} app={app} />
    </div>
  )
}
