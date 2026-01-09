import { createFileRoute } from '@tanstack/react-router'
import { DatabasesListView } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectDatabases, fetchProject, fetchOrganizationPlan } from '@/lib/react-query/hooks'

const DATABASES_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/databases/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Prefetch project to get teamId
      const projectData = await queryClient.fetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Prefetch organization plan if we have a teamId
      if (projectData?.teamId) {
        await queryClient.prefetchQuery({
          queryKey: ['organization', 'plan', projectData.teamId],
          queryFn: () => fetchOrganizationPlan(projectData.teamId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })
      }

      // Prefetch databases for the project (initial page, no search)
      await queryClient.prefetchQuery({
        queryKey: ['databases', 'project', projectId, 0, DATABASES_PER_PAGE, ''],
        queryFn: () => fetchProjectDatabases(projectId, 0, DATABASES_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch total count for limit checking
      await queryClient.prefetchQuery({
        queryKey: ['databases', 'project', projectId, 'total'],
        queryFn: () => fetchProjectDatabases(projectId, 0, 1, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DatabasesIndexPage,
})

function DatabasesIndexPage() {
  return <DatabasesListView />
}
