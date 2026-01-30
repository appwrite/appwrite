import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/security',
)({
  component: AuthSecurityPage,
})

function AuthSecurityPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-security`} />
}
