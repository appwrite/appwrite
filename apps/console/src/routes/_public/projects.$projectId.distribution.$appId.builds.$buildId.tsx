import { createFileRoute } from '@tanstack/react-router'
import {
  View,
  type BuildInitialData,
} from '@/components/pages/projects/$projectId/distribution/$appId/builds/$buildId/View'
import {
  distributionBuildLogsQueryOptions,
  distributionBuildQueryOptions,
  distributionReleaseQueryOptions,
  distributionTargetsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/builds/$buildId',
)({
  head: () => ({ meta: [{ title: pageTitle('Build', 'Distribution') }] }),
  loader: async ({
    params,
    context,
  }): Promise<BuildInitialData | undefined> => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId, buildId } = params
    const { queryClient } = context
    const optional = <T,>(request: Promise<T>) => request.catch(() => undefined)
    try {
      const [build, targets] = await Promise.all([
        queryClient.ensureQueryData(
          distributionBuildQueryOptions(projectId, appId, buildId),
        ),
        queryClient.ensureQueryData(
          distributionTargetsQueryOptions(projectId, appId),
        ),
        optional(
          queryClient.ensureInfiniteQueryData(
            distributionBuildLogsQueryOptions(projectId, appId, buildId),
          ),
        ),
      ])
      if (build.releaseId) {
        await optional(
          queryClient.ensureQueryData(
            distributionReleaseQueryOptions(projectId, appId, build.releaseId),
          ),
        )
      }
      return { build, targets: targets.targets }
    } catch (error) {
      console.warn('Failed to load the distribution build:', error)
      return undefined
    }
  },
  component: BuildPage,
})

function BuildPage() {
  const { buildId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return <View key={`distribution-build-${buildId}`} initialData={loaderData} />
}
