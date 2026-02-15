import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/security',
)({
  head: () => ({ meta: [{ title: pageTitle('Security', 'Auth') }] }),
  component: AuthSecurityPage,
})

function AuthSecurityPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-security`} />
}
