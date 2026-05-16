import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  bucketsQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { WorkspaceLayout } from '@/components/pages/projects/$projectId/storage/_components/WorkspaceLayout'

const SIDEBAR_BUCKETS_PREFETCH = 100

export const Route = createFileRoute('/_public/projects/$projectId/storage')({
  head: () => ({ meta: [{ title: pageTitle('Storage') }] }),
  loader: async ({ params, context, location, cause, preload }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    const bucketsOpts = bucketsQueryOptions(
      projectId,
      0,
      SIDEBAR_BUCKETS_PREFETCH,
      undefined,
      undefined,
      'name',
      'asc',
    )

    await Promise.all([
      queryClient.ensureQueryData(bucketsOpts),
      projectData?.teamId
        ? queryClient.ensureQueryData(
            organizationPlanQueryOptions(projectData.teamId),
          )
        : Promise.resolve(),
    ])

    // `/projects/:id/storage` (no trailing slash) matches this layout but not always
    // the index route loader; handle first-bucket redirect here so both URLs behave the same.
    const pathParts = location.pathname.split('/').filter(Boolean)
    const onStorageIndex =
      pathParts.length === 3 &&
      pathParts[0] === 'projects' &&
      pathParts[1] === projectId &&
      pathParts[2] === 'storage'

    // Link hover uses preload intent (`defaultPreload: 'intent'`). Throwing redirect
    // here would commit navigation without a click — only redirect on real visits.
    if (
      onStorageIndex &&
      !new URLSearchParams(location.search).get('create') &&
      cause !== 'preload' &&
      !preload
    ) {
      const bucketsData = queryClient.getQueryData(bucketsOpts.queryKey) as
        | { buckets?: { $id?: string }[] }
        | undefined
      const firstId = bucketsData?.buckets?.[0]?.$id
      if (firstId) {
        throw redirect({
          to: '/projects/$projectId/storage/$bucketId',
          params: { projectId, bucketId: firstId },
          replace: true,
        })
      }
    }
  },
  component: StorageLayout,
})

function StorageLayout() {
  return <WorkspaceLayout />
}
