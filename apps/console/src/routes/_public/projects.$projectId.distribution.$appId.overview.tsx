import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/$appId/overview/View'
import {
  PLATFORMS,
  PLATFORM_PROVIDERS,
} from '@/components/pages/projects/$projectId/distribution/_components/platform'
import { canAccessDistributionSubmissions } from '@/lib/console-rbac-loader'
import {
  allDistributionConnectionsQueryOptions,
  allDistributionSigningIdentitiesQueryOptions,
  distributionLatestBuildQueryOptions,
  distributionLatestSubmissionQueryOptions,
  distributionTargetsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/overview',
)({
  head: () => ({ meta: [{ title: pageTitle('Overview', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId } = params
    const { queryClient } = context
    const optional = <T,>(request: Promise<T>) => request.catch(() => undefined)
    try {
      const [targets, connections, signingIdentities, showSubmissions] =
        await Promise.all([
          queryClient.ensureQueryData(
            distributionTargetsQueryOptions(projectId, appId),
          ),
          Promise.all(
            PLATFORMS.map((platform) =>
              optional(
                queryClient.ensureQueryData(
                  allDistributionConnectionsQueryOptions(
                    projectId,
                    PLATFORM_PROVIDERS[platform],
                  ),
                ),
              ),
            ),
          ),
          Promise.all(
            PLATFORMS.map((platform) =>
              optional(
                queryClient.ensureQueryData(
                  allDistributionSigningIdentitiesQueryOptions(
                    projectId,
                    platform,
                  ),
                ),
              ),
            ),
          ),
          canAccessDistributionSubmissions(queryClient, projectId),
        ])
      await Promise.all(
        targets.targets.flatMap((target) => [
          optional(
            queryClient.ensureQueryData(
              distributionLatestBuildQueryOptions(projectId, appId, target.$id),
            ),
          ),
          ...(showSubmissions
            ? [
                optional(
                  queryClient.ensureQueryData(
                    distributionLatestSubmissionQueryOptions(
                      projectId,
                      appId,
                      target.$id,
                    ),
                  ),
                ),
              ]
            : []),
        ]),
      )
      return {
        targets: targets.targets,
        connections: connections.flatMap((list) => list ?? []),
        signingIdentities: signingIdentities.flatMap((list) => list ?? []),
      }
    } catch (error) {
      console.warn('Failed to load the distribution overview:', error)
      return undefined
    }
  },
  component: OverviewPage,
})

function OverviewPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View key={`distribution-app-${appId}-overview`} initialData={loaderData} />
  )
}
