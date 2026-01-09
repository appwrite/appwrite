import { createFileRoute } from '@tanstack/react-router'
import { BucketDetailView } from '@/components/pages/projects/$projectId/storage/BucketDetail'
import { fetchBucket } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/settings',
)({
  loader: async ({ params, context }) => {
    const { projectId, bucketId } = params
    const { queryClient } = context

    if (projectId && bucketId) {
      await queryClient.prefetchQuery({
        queryKey: ['bucket', 'project', projectId, bucketId],
        queryFn: () => fetchBucket(projectId, bucketId),
        staleTime: 30 * 1000,
      })
    }
  },
  component: BucketSettingsPage,
})

function BucketSettingsPage() {
  return <BucketDetailView />
}
