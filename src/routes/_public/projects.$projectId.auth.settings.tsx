import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/settings',
)({
  component: AuthSettingsPage,
})

function AuthSettingsPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-settings`} />
}
