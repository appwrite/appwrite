import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/$appId/artifacts/View'
import {
  DEFAULT_PAGE_SIZE,
  distributionArtifactsQueryOptions,
  distributionTargetsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/artifacts',
)({
  head: () => ({ meta: [{ title: pageTitle('Artifacts', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId } = params
    const { queryClient } = context
    try {
      const [artifacts, targets] = await Promise.all([
        queryClient.ensureQueryData(
          distributionArtifactsQueryOptions(
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
      return { artifacts, targets: targets.targets }
    } catch (error) {
      console.warn('Failed to load distribution artifacts:', error)
      return undefined
    }
  },
  component: ArtifactsPage,
})

function ArtifactsPage() {
  const { appId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      key={`distribution-app-${appId}-artifacts`}
      initialData={loaderData}
    />
  )
}
