import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/$appId/targets/View'
import {
  PLATFORMS,
  PLATFORM_PROVIDERS,
} from '@/components/pages/projects/$projectId/distribution/_components/platform'
import {
  allDistributionConnectionsQueryOptions,
  allDistributionSigningIdentitiesQueryOptions,
  distributionTargetsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/targets',
)({
  head: () => ({ meta: [{ title: pageTitle('Targets', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId } = params
    const { queryClient } = context
    const optional = <T,>(request: Promise<T>) => request.catch(() => undefined)
    try {
      const [targets, connections, signingIdentities] = await Promise.all([
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
      ])
      return {
        targets: targets.targets,
        connections: connections.flatMap((list) => list ?? []),
        signingIdentities: signingIdentities.flatMap((list) => list ?? []),
      }
    } catch (error) {
      console.warn('Failed to load distribution targets:', error)
      return undefined
    }
  },
  component: TargetsPage,
})

function TargetsPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View key={`distribution-app-${appId}-targets`} initialData={loaderData} />
  )
}
