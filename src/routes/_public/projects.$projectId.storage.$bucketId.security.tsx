import { createFileRoute } from '@tanstack/react-router'
import { BucketDetailView } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import { fetchBucket } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/security',
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
  component: BucketSecurityPage,
})

function BucketSecurityPage() {
  return <BucketDetailView />
}


