import { createFileRoute } from '@tanstack/react-router'
import { FileView } from '@/components/pages/projects/$projectId/storage/FileView'
import { fetchFile } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/files/$fileId/security',
)({
  loader: async ({ params, context }) => {
    const { projectId, bucketId, fileId } = params
    const { queryClient } = context

    if (projectId && bucketId && fileId) {
      await queryClient.prefetchQuery({
        queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
        queryFn: () => fetchFile(projectId, bucketId, fileId),
        staleTime: 30 * 1000,
      })
    }
  },
  component: FileSecurityPage,
})

function FileSecurityPage() {
  return <FileView />
}
