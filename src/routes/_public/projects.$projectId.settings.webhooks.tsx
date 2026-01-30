import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/settings/View'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/webhooks',
)({
  component: SettingsWebhooksPage,
})

function SettingsWebhooksPage() {
  return <View />
}
