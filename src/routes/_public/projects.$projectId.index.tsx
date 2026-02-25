import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/overview/Overview'
import { fetchProject } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/')({
  head: () => ({ meta: [{ title: pageTitle('Overview') }] }),
  loader: async ({ params, context }) => {
    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Fetch project (needed for overview) - blocks navigation
      // Use ensureQueryData to avoid duplicate calls and handle auth errors gracefully
      try {
        await queryClient.ensureQueryData({
          queryKey: ['project', projectId],
          queryFn: () => fetchProject(projectId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })
      } catch (error) {
        // If authentication is not set up yet, the component will handle it via RequireAuth
        // Don't block navigation - let the component handle the error
        console.warn('Failed to fetch project in loader:', error)
      }
    }
  },
  component: ProjectOverviewPage,
})

function ProjectOverviewPage() {
  const { projectId } = Route.useParams()
  return <View projectId={projectId} />
}
