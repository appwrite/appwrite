import { View } from '@/components/pages/organizations/$orgId/apps/$appId/secrets/View'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/secrets',
)({
  component: OrgAppSecretsPage,
})

function OrgAppSecretsPage() {
  return <View />
}
