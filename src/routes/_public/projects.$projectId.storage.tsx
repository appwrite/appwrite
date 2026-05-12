import { createFileRoute } from '@tanstack/react-router'
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
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    await Promise.all([
      queryClient.ensureQueryData(
        bucketsQueryOptions(
          projectId,
          0,
          SIDEBAR_BUCKETS_PREFETCH,
          undefined,
          undefined,
          'name',
          'asc',
        ),
      ),
      projectData?.teamId
        ? queryClient.ensureQueryData(
            organizationPlanQueryOptions(projectData.teamId),
          )
        : Promise.resolve(),
    ])
  },
  component: StorageLayout,
})

function StorageLayout() {
  return <WorkspaceLayout />
}
