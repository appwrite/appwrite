import { View } from '@/components/pages/organizations/$orgId/apps/$appId/support/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/support',
)({
  component: OrgAppSupportPage,
})

function OrgAppSupportPage() {
  return <View />
}
