import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewAuthorsQueryOptions,
  reviewCategoriesQueryOptions,
  reviewRepositoriesQueryOptions,
  reviewSummaryQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

/** Which range and repository the tab covers; other tabs link to one repository. */
const searchSchema = z.object({
  range: z.enum(['7d', '30d', '90d']).optional().catch(undefined),
  repositoryId: z.string().optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/reviews/performance',
)({
  head: () => ({ meta: [{ title: pageTitle('Performance', 'Review') }] }),
  validateSearch: searchSchema,
  loaderDeps: ({ search }) => ({
    range: search.range,
    repositoryId: search.repositoryId ?? '',
  }),
  loader: async ({ params, context, deps }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    const { range, repositoryId } = deps

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Categories take longest and sit below the authors, so the tab opens
    // without waiting for them.
    void queryClient.prefetchQuery(
      reviewCategoriesQueryOptions(projectId, range, repositoryId),
    )
    // The summary leads the tab, but the authors can stand in for its tiles,
    // so a summary that fails does not keep the tab from opening.
    await Promise.all([
      queryClient.ensureQueryData(
        reviewAuthorsQueryOptions(projectId, range, repositoryId),
      ),
      queryClient.ensureQueryData(reviewRepositoriesQueryOptions(projectId)),
      queryClient
        .ensureQueryData(
          reviewSummaryQueryOptions(projectId, range, repositoryId),
        )
        .catch(() => undefined),
    ])
  },
  component: PerformancePage,
})

function PerformancePage() {
  const { projectId } = Route.useParams()
  return <View key={`reviews-${projectId}-performance`} />
}
