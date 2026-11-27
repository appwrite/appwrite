import { createFileRoute } from '@tanstack/react-router'
import {
  View,
  type ReleaseInitialData,
} from '@/components/pages/projects/$projectId/distribution/$appId/releases/$releaseId/View'
import { canAccessDistributionSubmissions } from '@/lib/console-rbac-loader'
import {
  distributionReleaseBuildsQueryOptions,
  distributionReleaseQueryOptions,
  distributionSubmissionsQueryOptions,
  distributionTargetsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/releases/$releaseId',
)({
  head: ({ loaderData }) => ({
    meta: [{ title: pageTitle(releaseVersion(loaderData), 'Distribution') }],
  }),
  loader: async ({
    params,
    context,
  }): Promise<ReleaseInitialData | undefined> => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId, releaseId } = params
    const { queryClient } = context
    const optional = <T,>(request: Promise<T>) => request.catch(() => undefined)
    try {
      const [release, builds, targets, submissions] = await Promise.all([
        queryClient.ensureQueryData(
          distributionReleaseQueryOptions(projectId, appId, releaseId),
        ),
        optional(
          queryClient.ensureQueryData(
            distributionReleaseBuildsQueryOptions(projectId, appId, releaseId),
          ),
        ),
        queryClient.ensureQueryData(
          distributionTargetsQueryOptions(projectId, appId),
        ),
        canAccessDistributionSubmissions(queryClient, projectId).then(
          (showSubmissions) =>
            showSubmissions
              ? optional(
                  queryClient.ensureQueryData(
                    distributionSubmissionsQueryOptions(
                      projectId,
                      appId,
                      releaseId,
                    ),
                  ),
                )
              : undefined,
        ),
      ])
      return {
        release,
        builds: builds ?? [],
        targets: targets.targets,
        submissions: submissions ?? [],
      }
    } catch (error) {
      console.warn('Failed to load the distribution release:', error)
      return undefined
    }
  },
  component: ReleasePage,
})

function ReleasePage() {
  const { releaseId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View key={`distribution-release-${releaseId}`} initialData={loaderData} />
  )
}

function releaseVersion(loaderData: ReleaseInitialData | undefined): string {
  return loaderData?.release.version ?? 'Release'
}
