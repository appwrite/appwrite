import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewRepositoriesQueryOptions,
  vcsInstallationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/repositories',
)({
  head: () => ({ meta: [{ title: pageTitle('Repositories', 'Review') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(reviewRepositoriesQueryOptions(projectId)),
      // Rows name their installation, and the add dialog lists them.
      queryClient
        .ensureQueryData(vcsInstallationsQueryOptions(projectId, 0, 100))
        .catch(() => null),
    ])
  },
  component: RepositoriesPage,
})

function RepositoriesPage() {
  const { projectId } = Route.useParams()
  return <View key={`reviews-${projectId}-repositories`} />
}
