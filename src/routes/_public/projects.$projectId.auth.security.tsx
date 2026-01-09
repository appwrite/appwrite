import { createFileRoute } from '@tanstack/react-router'
import { AuthView } from '@/components/pages/projects/$projectId/auth/View'

export const Route = createFileRoute('/_public/projects/$projectId/auth/security')({
  component: AuthSecurityPage,
})

function AuthSecurityPage() {
  const { projectId } = Route.useParams()
  return <AuthView key={`auth-${projectId}-security`} />
}

