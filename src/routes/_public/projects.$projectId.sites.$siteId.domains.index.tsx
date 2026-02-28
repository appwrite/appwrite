import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Domains'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/',
)({
  component: SiteDomainsListPage,
})

function SiteDomainsListPage() {
  return <View />
}
