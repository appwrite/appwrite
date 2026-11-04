import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewRepositoriesQueryOptions,
  reviewsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/reviews/')({
  head: () => ({ meta: [{ title: pageTitle('Review') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Repositories decide the empty state, so both load before the page.
    await Promise.all([
      queryClient.ensureQueryData(
        reviewsQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE),
      ),
      queryClient.ensureQueryData(reviewRepositoriesQueryOptions(projectId)),
    ])
  },
  component: ReviewsIndexPage,
})

function ReviewsIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`reviews-${projectId}`} />
}
