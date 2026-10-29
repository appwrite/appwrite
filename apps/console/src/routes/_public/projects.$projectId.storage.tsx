import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import {
  isRealStorageNavigation,
  redirectStorageFirstBucketOrPlaceholder,
  storageSidebarBucketsQueryOptions,
} from '@/lib/storage-routes'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'
import { WorkspaceLayout } from '@/components/pages/projects/$projectId/storage/_components/WorkspaceLayout'

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

    const bucketsOpts = storageSidebarBucketsQueryOptions(projectId)

    await queryClient.ensureQueryData(bucketsOpts)

    await (projectData?.teamId
      ? queryClient.ensureQueryData(
          organizationPlanQueryOptions(projectData.teamId),
        )
      : Promise.resolve())

    // `/projects/:id/storage` (no trailing slash) matches this layout but not the index route.
    const pathParts = location.pathname.split('/').filter(Boolean)
    const onStorageIndex =
      pathParts.length === 3 &&
      pathParts[0] === 'projects' &&
      pathParts[1] === projectId &&
      pathParts[2] === 'storage'

    if (
      onStorageIndex &&
      !searchParamsFromRouterLocation(location).get('create') &&
      isRealStorageNavigation(cause, preload)
    ) {
      const freshBucketsData = await queryClient.fetchQuery(bucketsOpts)
      redirectStorageFirstBucketOrPlaceholder(projectId, freshBucketsData)
    }
  },
  component: StorageLayout,
})

function StorageLayout() {
  return <WorkspaceLayout />
}
