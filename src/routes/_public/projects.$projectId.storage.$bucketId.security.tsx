import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import { fetchBucket } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/security',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, bucketId } = params
    const { queryClient } = context

    if (projectId && bucketId) {
      // Fetch critical data before rendering to prevent layout shifts
      // fetchQuery blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: ['bucket', 'project', projectId, bucketId],
        queryFn: () => fetchBucket(projectId, bucketId),
        staleTime: 30 * 1000,
      })
    }
  },
  component: BucketSecurityPage,
})

function BucketSecurityPage() {
  return <View />
}
