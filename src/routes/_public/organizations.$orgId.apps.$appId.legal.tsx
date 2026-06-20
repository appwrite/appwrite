import { View } from '@/components/pages/organizations/$orgId/apps/$appId/legal/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/legal',
)({
  component: OrgAppLegalPage,
})

function OrgAppLegalPage() {
  return <View />
}
