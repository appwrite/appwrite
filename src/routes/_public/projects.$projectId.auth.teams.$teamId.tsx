import { createFileRoute } from '@tanstack/react-router'
import { TeamDetailView } from '@/components/pages/projects/$projectId/auth/teams/View'
import { fetchTeam } from '@/lib/react-query/hooks/users'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/teams/$teamId',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, teamId } = params
    const { queryClient } = context

    // Prefetch team data
    if (projectId && teamId) {
      await queryClient.ensureQueryData({
        queryKey: ['team', 'project', projectId, teamId],
        queryFn: () => fetchTeam(projectId, teamId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: TeamDetailPage,
})

function TeamDetailPage() {
  const { projectId, teamId } = Route.useParams()
  return <TeamDetailView key={`team-${projectId}-${teamId}`} />
}
