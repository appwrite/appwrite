import { createFileRoute, Outlet } from '@tanstack/react-router'
import { fetchBucket } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/storage/$bucketId',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.bucket?.name ?? 'Bucket',
          'Storage',
        ),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, bucketId } = params
    const { queryClient } = context
    if (!projectId || !bucketId) return
    await queryClient.fetchQuery({
      queryKey: ['bucket', 'project', projectId, bucketId],
      queryFn: () => fetchBucket(projectId, bucketId),
      staleTime: 30 * 1000,
    })
    const bucket = queryClient.getQueryData<Awaited<ReturnType<typeof fetchBucket>>>([
      'bucket',
      'project',
      projectId,
      bucketId,
    ])
    return { bucket }
  },
  component: BucketLayout,
})

function BucketLayout() {
  return <Outlet />
}
