import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/storage/$bucketId/View'
import { fetchBucket } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessBucketSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.bucket?.name ?? 'Bucket', 'Storage'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, bucketId } = params
    const { queryClient } = context

    if (!projectId || !bucketId) return

    const canAccess = await canAccessBucketSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId, bucketId },
        replace: true,
      })
    }

    await queryClient.fetchQuery({
      queryKey: ['bucket', 'project', projectId, bucketId],
      queryFn: () => fetchBucket(projectId, bucketId),
      staleTime: 30 * 1000,
    })
  },
  component: BucketSettingsPage,
})

function BucketSettingsPage() {
  return <View />
}
