import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/reviews/View'
import {
  projectQueryOptions,
  reviewRepositoriesQueryOptions,
  reviewsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { listSearchSchema, queryParamToMap } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = listSearchSchema.pick({ page: true, query: true })

export const Route = createFileRoute('/_public/projects/$projectId/reviews/')({
  head: () => ({ meta: [{ title: pageTitle('Review') }] }),
  validateSearch: searchSchema,
  loaderDeps: ({ search }) => ({ page: search.page, query: search.query }),
  loader: async ({ params, context, deps }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // The same page and filters the view asks for, so it opens on cached data.
    const filters = queryParamToMap(deps.query ?? null)
    const filterQueries =
      filters.size > 0 ? Array.from(filters.values()) : undefined
    // Repositories decide the empty state, so both load before the page.
    await Promise.all([
      queryClient.ensureQueryData(
        reviewsQueryOptions(
          projectId,
          (deps.page ?? 1) - 1,
          DEFAULT_PAGE_SIZE,
          filterQueries,
        ),
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
