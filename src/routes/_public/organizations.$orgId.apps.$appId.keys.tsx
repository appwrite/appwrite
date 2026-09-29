import { View } from '@/components/pages/organizations/$orgId/apps/$appId/keys/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/keys',
)({
  component: OrgAppKeysPage,
})

function OrgAppKeysPage() {
  return <View />
}
