import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/stores/$appId/View'
import {
  distributionAppQueryOptions,
  distributionBuildsQueryOptions,
  distributionSubmissionsQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/stores/$appId/',
)({
  head: () => ({ meta: [{ title: pageTitle('App', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, appId } = params
    const { queryClient } = context
    if (!projectId || !appId) return

    try {
      await queryClient.ensureQueryData(projectQueryOptions(projectId))

      const [app] = await Promise.all([
        queryClient.ensureQueryData(
          distributionAppQueryOptions(projectId, appId),
        ),
        queryClient.ensureQueryData(
          distributionBuildsQueryOptions(
            projectId,
            appId,
            0,
            DEFAULT_PAGE_SIZE,
          ),
        ),
        queryClient.ensureQueryData(
          distributionSubmissionsQueryOptions(
            projectId,
            appId,
            0,
            DEFAULT_PAGE_SIZE,
          ),
        ),
      ])

      return { app }
    } catch (error) {
      console.warn('Failed to fetch distribution app in loader:', error)
    }
  },
  component: StoreAppPage,
})

function StoreAppPage() {
  const { projectId, appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      key={`store-app-${projectId}-${appId}`}
      initialData={loaderData?.app ? { app: loaderData.app } : undefined}
    />
  )
}
