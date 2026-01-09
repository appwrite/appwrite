import { createFileRoute } from '@tanstack/react-router'
import { DashboardOverview } from '@/components/pages/projects/$projectId/overview/Overview'
import { fetchProject } from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/projects/$projectId/')({
  loader: async ({ params, context }) => {
    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      await queryClient.prefetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })
    }
  },
  component: ProjectOverviewPage,
})

function ProjectOverviewPage() {
  const { projectId } = Route.useParams()
  return <DashboardOverview projectId={projectId} />
}
