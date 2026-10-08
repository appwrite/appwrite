import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/teams/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { fetchTeam } from '@/lib/react-query/hooks/users'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/teams/$teamId/activity',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().activity) {
      throw redirect({
        to: '/projects/$projectId/auth/teams/$teamId',
        params: { projectId: params.projectId, teamId: params.teamId },
        replace: true,
      })
    }
  },
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
      // Fetch team data - blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: ['team', 'project', projectId, teamId],
        queryFn: () => fetchTeam(projectId, teamId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: TeamActivityPage,
})

function TeamActivityPage() {
  const { projectId, teamId } = Route.useParams()
  return <View key={`team-${projectId}-${teamId}`} />
}
