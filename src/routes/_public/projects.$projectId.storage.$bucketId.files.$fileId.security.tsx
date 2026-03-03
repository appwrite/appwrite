import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/files/$fileId/View'
import { fetchFile } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessBucketSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/files/$fileId/security',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.file?.name ?? 'File', 'Storage'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, bucketId, fileId } = params
    const { queryClient } = context

    if (!projectId || !bucketId || !fileId) return

    const canAccess = await canAccessBucketSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/storage/$bucketId/files/$fileId',
        params: { projectId, bucketId, fileId },
        replace: true,
      })
    }

    await queryClient.fetchQuery({
      queryKey: ['file', 'project', projectId, 'bucket', bucketId, fileId],
      queryFn: () => fetchFile(projectId, bucketId, fileId),
      staleTime: 30 * 1000,
    })
  },
  component: FileSecurityPage,
})

function FileSecurityPage() {
  return <View />
}
