import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/settings',
)({
  head: () => ({ meta: [{ title: pageTitle('Settings', 'Auth') }] }),
  component: AuthSettingsPage,
})

function AuthSettingsPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-settings`} />
}
