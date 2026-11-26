import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewLearningsQueryOptions,
  reviewRepositoriesQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

/** The repository the list is narrowed to; other tabs link to one repository. */
const searchSchema = z.object({
  repositoryId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/learnings',
)({
  head: () => ({ meta: [{ title: pageTitle('Learnings', 'Review') }] }),
  validateSearch: searchSchema,
  loaderDeps: ({ search }) => ({ repositoryId: search.repositoryId ?? '' }),
  loader: async ({ params, context, deps }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await Promise.all([
      queryClient.ensureQueryData(
        reviewLearningsQueryOptions(
          projectId,
          0,
          DEFAULT_PAGE_SIZE,
          deps.repositoryId,
        ),
      ),
      // The tab's count stays the unfiltered total.
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
