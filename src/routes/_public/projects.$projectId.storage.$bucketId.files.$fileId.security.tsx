import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/files/$fileId/View'
import { fetchFile } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/files/$fileId/security',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, bucketId, fileId } = params
    const { queryClient } = context

    if (projectId && bucketId && fileId) {
      // Fetch critical data before rendering to prevent layout shifts
      // fetchQuery blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
        queryFn: () => fetchFile(projectId, bucketId, fileId),
        staleTime: 30 * 1000,
      })
    }
  },
  component: FileSecurityPage,
})

function FileSecurityPage() {
  return <View />
}
