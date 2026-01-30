import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/files/$fileId/View'
import { fetchFile, fetchFileTokens } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/files/$fileId',
)({
  loader: async ({ params, context }) => {
    const { projectId, bucketId, fileId } = params
    const { queryClient } = context

    if (projectId && bucketId && fileId) {
      // Fetch critical data before rendering to prevent layout shifts
      await Promise.all([
        // Fetch file - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
          queryFn: () => fetchFile(projectId, bucketId, fileId),
          staleTime: 30 * 1000,
        }),
        // Fetch first page of file tokens - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: [
            'file-tokens',
            'project',
            projectId,
            'bucket',
            bucketId,
            fileId,
            0,
            25,
          ],
          queryFn: () => fetchFileTokens(projectId, bucketId, fileId, 0, 25),
          staleTime: 30 * 1000,
        }),
      ])
    }
  },
  component: FileViewPage,
})

function FileViewPage() {
  return <View />
}
