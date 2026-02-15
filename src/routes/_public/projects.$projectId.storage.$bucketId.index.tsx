import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import { fetchBucket, fetchBucketFiles } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const FILES_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.bucket?.name ?? 'Bucket',
          'Storage',
        ),
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
      // Fetch critical data before rendering to prevent layout shifts
      await Promise.all([
        // Fetch bucket - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['bucket', 'project', projectId, bucketId],
          queryFn: () => fetchBucket(projectId, bucketId),
          staleTime: 30 * 1000,
        }),
        // Fetch first page of files - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: [
            'files',
            'project',
            projectId,
            'bucket',
            bucketId,
            0,
            FILES_PER_PAGE,
            '',
          ],
          queryFn: () =>
            fetchBucketFiles(projectId, bucketId, 0, FILES_PER_PAGE, ''),
          staleTime: 30 * 1000,
        }),
      ])
      const bucket = queryClient.getQueryData<Awaited<ReturnType<typeof fetchBucket>>>([
        'bucket',
        'project',
        projectId,
        bucketId,
      ])
      return { bucket }
    }
  },
  component: BucketFilesPage,
})

function BucketFilesPage() {
  return <View />
}
