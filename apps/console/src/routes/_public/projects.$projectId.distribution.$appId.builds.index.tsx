import { createFileRoute } from '@tanstack/react-router'
import {
  View,
  type BuildsInitialData,
} from '@/components/pages/projects/$projectId/distribution/$appId/builds/View'
import {
  distributionBuildsQueryOptions,
  distributionTargetsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/builds/',
)({
  head: () => ({ meta: [{ title: pageTitle('Builds', 'Distribution') }] }),
  loader: async ({
    params,
    context,
  }): Promise<BuildsInitialData | undefined> => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId } = params
    const { queryClient } = context
    try {
      const [builds, targets] = await Promise.all([
        queryClient.ensureQueryData(
          distributionBuildsQueryOptions(
            projectId,
            appId,
            0,
            DEFAULT_PAGE_SIZE,
          ),
        ),
        queryClient.ensureQueryData(
          distributionTargetsQueryOptions(projectId, appId),
        ),
      ])
      return { builds, targets: targets.targets }
    } catch (error) {
      console.warn('Failed to load distribution builds:', error)
      return undefined
    }
  },
  component: BuildsPage,
})

function BuildsPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View key={`distribution-app-${appId}-builds`} initialData={loaderData} />
  )
}
