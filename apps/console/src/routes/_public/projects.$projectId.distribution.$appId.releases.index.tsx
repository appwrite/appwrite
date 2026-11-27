import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/distribution/$appId/releases/View'
import {
  DEFAULT_PAGE_SIZE,
  distributionReleasesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution/$appId/releases/',
)({
  head: () => ({ meta: [{ title: pageTitle('Releases', 'Distribution') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId, appId } = params
    try {
      await context.queryClient.ensureQueryData(
        distributionReleasesQueryOptions(
          projectId,
          appId,
          0,
          DEFAULT_PAGE_SIZE,
          '',
        ),
      )
    } catch (error) {
      console.warn('Failed to load distribution releases:', error)
    }
    return undefined
  },
  component: ReleasesPage,
})

function ReleasesPage() {
  const { appId } = Route.useParams()
  return <View key={`distribution-app-${appId}-releases`} />
}
