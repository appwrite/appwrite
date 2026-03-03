import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/teams/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { fetchTeam, fetchTeamMemberships } from '@/lib/react-query/hooks/users'
import { pageTitle } from '@/lib/utils/page-title'

const MEMBERSHIPS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/teams/$teamId/members',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.team?.name ?? 'Team', 'Auth'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, teamId } = params
    const { queryClient } = context

    if (projectId && teamId) {
      // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
      await queryClient.ensureQueryData(projectQueryOptions(projectId))
      // Fetch critical data before rendering to prevent layout shifts
      await Promise.all([
        // Fetch team - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['team', 'project', projectId, teamId],
          queryFn: () => fetchTeam(projectId, teamId),
          staleTime: 30 * 1000,
        }),
        // Fetch first page of memberships - blocks navigation until ready
        queryClient.fetchQuery({
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
  return <View key={`team-${projectId}-${teamId}`} />
}
