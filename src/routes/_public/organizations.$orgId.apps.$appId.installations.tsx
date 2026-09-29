import { View } from '@/components/pages/organizations/$orgId/apps/$appId/installations/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/installations',
)({
  component: OrgAppInstallationsPage,
})

function OrgAppInstallationsPage() {
  return <View />
}
