import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewAuthorsQueryOptions,
  reviewCategoriesQueryOptions,
  reviewRepositoriesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/performance',
)({
  head: () => ({ meta: [{ title: pageTitle('Performance', 'Review') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Categories take longest and sit below the authors, so the tab opens
    // without waiting for them.
    void queryClient.prefetchQuery(reviewCategoriesQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(reviewAuthorsQueryOptions(projectId)),
      queryClient.ensureQueryData(reviewRepositoriesQueryOptions(projectId)),
    ])
  },
  component: PerformancePage,
})

function PerformancePage() {
  const { projectId } = Route.useParams()
  return <View key={`reviews-${projectId}-performance`} />
}
