import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/videos/View'
import {
  projectQueryOptions,
  videosQueryOptions,
  VIDEOS_DEFAULT_SORT_BY,
  VIDEOS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

const videosSearchSchema = listSearchSchema.extend({
  create: z.string().optional().catch(undefined),
})

export const Route = createFileRoute('/_public/projects/$projectId/videos/')({
  head: () => ({ meta: [{ title: pageTitle('Videos') }] }),
  validateSearch: videosSearchSchema,
  loaderDeps: ({ search }) => ({
    search: search.search,
    query: search.query,
    page: search.page,
    limit: search.limit,
    sort: search.sort,
  }),
  loader: async ({ params, context, deps }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context

    const { search, page, limit, filterQueries, sort } = parseListSearch(
      deps,
      { page: 1, limit: GRID_DEFAULT_PAGE_SIZE },
    )

    // Resolves the project region before project-scoped calls.
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient
      .ensureQueryData(
        videosQueryOptions(
          projectId,
          page - 1,
          limit,
          search ?? undefined,
          filterQueries,
          sort?.sortBy ?? VIDEOS_DEFAULT_SORT_BY,
          sort?.sortOrder ?? VIDEOS_DEFAULT_SORT_ORDER,
        ),
      )
      .catch(() => null)
  },
  component: VideosIndexPage,
})

function VideosIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`videos-${projectId}-index`} />
}
