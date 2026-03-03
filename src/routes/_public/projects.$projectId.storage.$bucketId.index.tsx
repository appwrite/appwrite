import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import {
  fetchBucket,
  bucketFilesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

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
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, bucketId } = params
    const { queryClient } = context

    if (projectId && bucketId) {
      // Fetch critical data before navigation completes to prevent layout shifts
      const [bucket] = await Promise.all([
        queryClient.fetchQuery({
          queryKey: ['bucket', 'project', projectId, bucketId],
          queryFn: () => fetchBucket(projectId, bucketId),
          staleTime: 30 * 1000,
        }),
        // Prefetch first page of files - blocks until ready, uses cache if fresh
        queryClient.ensureQueryData(
          bucketFilesQueryOptions(
            projectId,
            bucketId,
            0,
            DEFAULT_PAGE_SIZE,
            '',
          ),
        ),
      ])
      return { bucket }
    }
  },
  component: BucketFilesPage,
})

function BucketFilesPage() {
  return <View />
}
