import { View } from '@/components/pages/organizations/$orgId/apps/$appId/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/',
)({
  component: OrgAppGeneralPage,
})

function OrgAppGeneralPage() {
  return <View />
}
