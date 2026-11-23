import { View } from '@/components/pages/organizations/$orgId/apps/$appId/oauth/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/oauth',
)({
  component: OrgAppOAuthPage,
})

function OrgAppOAuthPage() {
  return <View />
}
