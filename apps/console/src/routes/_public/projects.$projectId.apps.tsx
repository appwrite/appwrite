import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/apps/View'
import {
  platformsQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/apps')({
  head: () => ({ meta: [{ title: pageTitle('Apps') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context

    if (!projectId) return undefined

    // Fetch and populate cache (same keys as hooks). Return data for first paint (no flash).
    const [, platformsResponse] = await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(platformsQueryOptions(projectId)),
    ])

    return {
      platforms: platformsResponse?.platforms ?? [],
    }
  },
  component: AppsPage,
})

function AppsPage() {
  const matches = useMatches()
  const loaderData = Route.useLoaderData()

  const isAddAppRoute = matches.some(
    (m) => m.routeId === '/_public/projects/$projectId/apps/add',
  )

  if (isAddAppRoute) {
    return (
      <div className="flex h-full min-h-0 flex-1 flex-col">
        <Outlet />
      </div>
    )
  }

  return (
    <View
      initialData={
        loaderData
          ? { platforms: loaderData.platforms }
          : undefined
      }
    />
  )
}
