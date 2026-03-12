import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import {
  fetchBucket,
  bucketFilesQueryOptions,
  FILES_DEFAULT_SORT_BY,
  FILES_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import {
  listSearchSchema,
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  getSort,
  queryParamToMap,
} from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

const DEFAULT_PAGE = 1

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.bucket?.name ?? 'Bucket', 'Storage'),
      },
    ],
  }),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId, bucketId } = params
    const { queryClient } = context
    if (!projectId || !bucketId) return

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const search = getSearch(url)
    const page = getPage(url, DEFAULT_PAGE)
    const limit = getLimit(url, DEFAULT_PAGE_SIZE)
    const queryParam = getQueryParam(url)
    const filterMap = queryParamToMap(queryParam)
    const filterQueries =
      filterMap.size > 0 ? Array.from(filterMap.values()) : undefined
    const sort = getSort(url)
    const sortBy = sort?.sortBy ?? FILES_DEFAULT_SORT_BY
    const sortOrder = sort?.sortOrder ?? FILES_DEFAULT_SORT_ORDER

    const [bucket] = await Promise.all([
      queryClient.fetchQuery({
        queryKey: ['bucket', 'project', projectId, bucketId],
        queryFn: () => fetchBucket(projectId, bucketId),
        staleTime: 30 * 1000,
      }),
      queryClient.ensureQueryData(
        bucketFilesQueryOptions(
          projectId,
          bucketId,
          page - 1,
          limit,
          search ?? undefined,
          undefined,
          filterQueries,
          sortBy,
          sortOrder,
        ),
      ),
    ])
    return { bucket }
  },
  component: BucketFilesPage,
})

function BucketFilesPage() {
  return <View />
}
