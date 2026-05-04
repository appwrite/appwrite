import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'
import {
  consoleOAuth2CatalogQueryOptions,
  projectOAuth2ProvidersQueryOptions,
} from '@/lib/react-query/hooks/oauth2-providers'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/settings',
)({
  head: () => ({ meta: [{ title: pageTitle('Settings', 'Auth') }] }),
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

    await Promise.all([
      queryClient.ensureQueryData(consoleOAuth2CatalogQueryOptions()),
      queryClient.ensureQueryData(projectOAuth2ProvidersQueryOptions(projectId)),
    ])
  },
  component: AuthSettingsPage,
})

function AuthSettingsPage() {
  const { projectId } = Route.useParams()
  return <View key={`auth-${projectId}-settings`} />
}
