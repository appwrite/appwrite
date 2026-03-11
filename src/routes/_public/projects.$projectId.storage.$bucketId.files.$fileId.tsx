import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/files/$fileId/View'
import { fetchFile, fetchFileTokens } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/files/$fileId',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.file?.name ?? 'File', 'Storage'),
      },
    ],
  }),
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
          queryFn: () => fetchFileTokens(projectId, bucketId, fileId, 0, 10),
          staleTime: 30 * 1000,
        }),
      ])
      const file = queryClient.getQueryData<{ name?: string }>([
        'file',
        'project',
        projectId,
        'bucket',
        bucketId,
        fileId,
      ])
      return { file }
    }
  },
  component: FileViewPage,
})

function FileViewPage() {
  return <View />
}
