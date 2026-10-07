import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/$websiteId/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/$websiteId',
)({
  head: () => ({ meta: [{ title: pageTitle('Website', 'Analytics') }] }),
  component: WebsiteAnalyticsPage,
})

function WebsiteAnalyticsPage() {
  const { projectId, websiteId } = Route.useParams()
  const navigate = useNavigate()

  // Mock website name lookup - in real app this would come from data
  const websiteNames: Record<string, string> = {
    web1: 'Main Marketing Site',
    web2: 'Documentation Portal',
    web3: 'Customer Dashboard',
    web4: 'Blog',
  }

  const websiteName = websiteNames[websiteId] || 'Website Analytics'

  const handleBack = () => {
    navigate({ to: '/projects/$projectId/analytics', params: { projectId } })
  }

  return (
    <View websiteId={websiteId} websiteName={websiteName} onBack={handleBack} />
  )
}
