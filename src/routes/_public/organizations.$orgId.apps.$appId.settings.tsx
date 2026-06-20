import { View } from '@/components/pages/organizations/$orgId/apps/$appId/settings/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/settings',
)({
  component: OrgAppSettingsPage,
})

function OrgAppSettingsPage() {
  return <View />
}
