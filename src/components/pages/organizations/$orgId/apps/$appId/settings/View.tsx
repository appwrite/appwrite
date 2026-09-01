import { useParams } from '@tanstack/react-router'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import { BrandingCard } from '../_components/BrandingCard'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  return <BrandingCard orgId={orgId} app={app} />
}
