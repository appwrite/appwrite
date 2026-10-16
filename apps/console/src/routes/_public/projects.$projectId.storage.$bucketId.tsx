import { createFileRoute, Outlet } from '@tanstack/react-router'
import { fetchBucket } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import {
  redirectStorageFirstBucketOrPlaceholder,
  STORAGE_PLACEHOLDER_BUCKET_ID,
  storageSidebarBucketsQueryOptions,
} from '@/lib/storage-routes'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.bucket?.name ?? 'Bucket', 'Storage'),
      },
    ],
  }),
  loader: async ({ params, context, cause, preload }) => {
    if (typeof window === 'undefined') return
    const { projectId, bucketId } = params
    const { queryClient } = context
    if (!projectId || !bucketId) return

    if (bucketId === STORAGE_PLACEHOLDER_BUCKET_ID) {
      if (cause === 'preload' || preload) {
        return { bucket: undefined }
      }

      const bucketsData = await queryClient.ensureQueryData(
        storageSidebarBucketsQueryOptions(projectId),
      )
      const firstId = bucketsData.buckets?.[0]?.$id
      if (firstId) {
        redirectStorageFirstBucketOrPlaceholder(projectId, bucketsData)
      }
      return { bucket: undefined }
    }

    await queryClient.fetchQuery({
      queryKey: ['bucket', 'project', projectId, bucketId],
      queryFn: () => fetchBucket(projectId, bucketId),
      staleTime: 30 * 1000,
    })
    const bucket = queryClient.getQueryData<
      Awaited<ReturnType<typeof fetchBucket>>
    >(['bucket', 'project', projectId, bucketId])
    return { bucket }
  },
  component: BucketLayout,
})

function BucketLayout() {
  return <Outlet />
}
