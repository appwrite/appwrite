import { createFileRoute } from '@tanstack/react-router'
import { TeamDetailView } from '@/components/pages/projects/$projectId/auth/teams/View'
import { fetchTeam, fetchTeamMemberships } from '@/lib/react-query/hooks/users'

const MEMBERSHIPS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/teams/$teamId/members',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, teamId } = params
    const { queryClient } = context

    // Prefetch team data and memberships
    if (projectId && teamId) {
      await Promise.all([
        queryClient.ensureQueryData({
          queryKey: ['team', 'project', projectId, teamId],
          queryFn: () => fetchTeam(projectId, teamId),
          staleTime: 30 * 1000,
        }),
        queryClient.ensureQueryData({
          queryKey: [
            'team',
            'memberships',
            'project',
            projectId,
            teamId,
            0,
            MEMBERSHIPS_PER_PAGE,
            '',
          ],
          queryFn: () =>
            fetchTeamMemberships(
              projectId,
              teamId,
              0,
              MEMBERSHIPS_PER_PAGE,
              '',
            ),
          staleTime: 30 * 1000,
        }),
      ])
    }
  },
  component: TeamMembersPage,
})

function TeamMembersPage() {
  const { projectId, teamId } = Route.useParams()
  return <TeamDetailView key={`team-${projectId}-${teamId}`} />
}
