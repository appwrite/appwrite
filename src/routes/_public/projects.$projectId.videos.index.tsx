import { createFileRoute } from '@tanstack/react-router'
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

const DEFAULT_PAGE = 1

export const Route = createFileRoute('/_public/projects/$projectId/videos/')({
  head: () => ({ meta: [{ title: pageTitle('Videos') }] }),
  validateSearch: listSearchSchema,
  loaderDeps: ({ search }) => search,
  loader: async ({ params, context, deps: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const { search, page, limit, filterQueries, sort } = parseListSearch(
      routeSearch,
      { page: DEFAULT_PAGE, limit: GRID_DEFAULT_PAGE_SIZE },
    )

    // Resolves the project region before project-scoped calls.
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
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
  },
  component: VideosIndexPage,
})

function VideosIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`videos-${projectId}-index`} />
}
