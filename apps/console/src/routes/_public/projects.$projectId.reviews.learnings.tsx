import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewLearningsQueryOptions,
  reviewRepositoriesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/learnings',
)({
  head: () => ({ meta: [{ title: pageTitle('Learnings', 'Review') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(reviewLearningsQueryOptions(projectId)),
      queryClient.ensureQueryData(reviewRepositoriesQueryOptions(projectId)),
    ])
  },
  component: LearningsPage,
})

function LearningsPage() {
  const { projectId } = Route.useParams()
  return <View key={`reviews-${projectId}-learnings`} />
}
