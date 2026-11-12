import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import {
  consoleOAuth2CatalogQueryOptions,
  projectOAuth2ProvidersQueryOptions,
  type AuthOAuth2SettingsInitialData,
} from '@/lib/react-query/hooks/oauth2-providers'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/social-providers',
)({
  head: () => ({ meta: [{ title: pageTitle('Social providers', 'Auth') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined
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

    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    const [catalog, providerList] = await Promise.all([
      queryClient.ensureQueryData(consoleOAuth2CatalogQueryOptions()),
      queryClient.ensureQueryData(
        projectOAuth2ProvidersQueryOptions(projectId),
      ),
    ])

    return { catalog, providerList } satisfies AuthOAuth2SettingsInitialData
  },
  component: () => null,
})
