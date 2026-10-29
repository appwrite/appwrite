import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View as BucketFilesView } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import { View as StorageIndexView } from '@/components/pages/projects/$projectId/storage/View'
import { STORAGE_PLACEHOLDER_BUCKET_ID } from '@/lib/storage-routes'
import {
  fetchBucket,
  bucketFilesQueryOptions,
  fileQueryOptions,
  fileTokensQueryOptions,
  FILES_DEFAULT_SORT_BY,
  FILES_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

const DEFAULT_PAGE = 1

const bucketFilesSearchSchema = listSearchSchema.extend({
  file: z.string().optional().catch(undefined),
  filePanel: z
    .enum(['overview', 'permissions', 'tokens', 'security'])
    .optional()
    .catch(undefined),
})

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
  validateSearch: bucketFilesSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId, bucketId } = params
    const { queryClient } = context
    if (!projectId || !bucketId) return

    if (bucketId === STORAGE_PLACEHOLDER_BUCKET_ID) {
      return
    }

    const routeSearchParams = routeSearch ?? {}
    const fileId = routeSearchParams.file?.trim() || undefined

    const { search, page, limit, filterQueries, sort } = parseListSearch(
      routeSearchParams,
      {
        page: DEFAULT_PAGE,
        limit: ROWS_DEFAULT_PAGE_SIZE,
      },
    )
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
      ...(fileId
        ? [
            queryClient.ensureQueryData(
              fileQueryOptions(projectId, bucketId, fileId),
            ),
            queryClient.ensureQueryData(
              fileTokensQueryOptions(projectId, bucketId, fileId, 0),
            ),
          ]
        : []),
    ])
    return { bucket }
  },
  component: BucketFilesPage,
})

function BucketFilesPage() {
  const { bucketId } = Route.useParams()
  if (bucketId === STORAGE_PLACEHOLDER_BUCKET_ID) {
    return <StorageIndexView />
  }
  return <BucketFilesView />
}
