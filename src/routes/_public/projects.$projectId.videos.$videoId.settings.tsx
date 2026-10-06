import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/videos/$videoId/settings/View'
import { projectQueryOptions, videoQueryOptions } from '@/lib/react-query/hooks'
import { canAccessVideoSettings } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/videos/$videoId/settings',
)({
  head: () => ({ meta: [{ title: pageTitle('Settings', 'Videos') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, videoId } = params
    const { queryClient } = context

    const canAccess = await canAccessVideoSettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/videos/$videoId',
        params: { projectId, videoId },
        replace: true,
      })
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const video = await queryClient.ensureQueryData(
      videoQueryOptions(projectId, videoId),
    )
    return { video }
  },
  component: SettingsPage,
})

function SettingsPage() {
  const initialData = Route.useLoaderData()
  return <View initialData={initialData} />
}
