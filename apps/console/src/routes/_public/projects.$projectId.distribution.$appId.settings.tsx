import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/$appId/settings/View'
import { canAccessDistributionAppSettings } from '@/lib/console-rbac-loader'
import {
  distributionAppQueryOptions,
  projectQueryOptions,
  vcsInstallationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/settings',
)({
  head: () => ({ meta: [{ title: pageTitle('Settings', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId, appId } = params
    const { queryClient } = context

    const canAccess = await canAccessDistributionAppSettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/distribution/$appId/targets',
        params: { projectId, appId },
        replace: true,
      })
    }

    try {
      const [app] = await Promise.all([
        queryClient.ensureQueryData(
          distributionAppQueryOptions(projectId, appId),
        ),
        queryClient.ensureQueryData(projectQueryOptions(projectId)),
        queryClient
          .ensureQueryData(vcsInstallationsQueryOptions(projectId))
          .catch(() => undefined),
      ])
      return { app }
    } catch (error) {
      console.warn('Failed to load the distribution app settings:', error)
      return undefined
    }
  },
  component: SettingsPage,
})

function SettingsPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View key={`distribution-app-${appId}-settings`} initialData={loaderData} />
  )
}
