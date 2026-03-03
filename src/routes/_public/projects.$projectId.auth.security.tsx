import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/security',
)({
  head: () => ({ meta: [{ title: pageTitle('Security', 'Auth') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return
    const canAccess = await canAccessAuthSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/auth',
        params: { projectId },
        replace: true,
      })
    }
  },
  component: AuthSecurityPage,
})

function AuthSecurityPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-security`} />
}
