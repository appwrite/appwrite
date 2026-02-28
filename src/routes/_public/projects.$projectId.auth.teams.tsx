import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/View'
import {
  fetchProjectTeams,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TEAMS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/auth/teams')(
  {
    head: () => ({ meta: [{ title: pageTitle('Teams', 'Auth') }] }),
    loader: async ({ params, context }) => {
      // Only run on client side (SDK requires browser environment)
      if (typeof window === 'undefined') {
        return
      }

      const { projectId } = params
      const { queryClient } = context

      if (projectId) {
        // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
        await queryClient.ensureQueryData(projectQueryOptions(projectId))
        // Fetch teams for the project (initial page, no search)
        // Fetch first page of teams - blocks navigation until ready
        await queryClient.fetchQuery({
          queryKey: ['teams', 'project', projectId, 0, TEAMS_PER_PAGE, ''],
          queryFn: () => fetchProjectTeams(projectId, 0, TEAMS_PER_PAGE, ''),
          staleTime: 30 * 1000, // 30 seconds
        })
      }
    },
    component: AuthTeamsPage,
  },
)

function AuthTeamsPage() {
  const { projectId } = Route.useParams()
  const matches = useMatches()

  // Check if we're on a child route (team detail, etc.)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/auth/teams/$teamId') ||
      match.routeId.startsWith(
        '/_public/projects/$projectId/auth/teams/$teamId',
      ),
  )

  // If we're on a child route, render the outlet (child route component)
  if (isChildRoute) {
    return <Outlet />
  }

  // Otherwise, show the teams list view
  return <View key={`auth-${projectId}-teams`} />
}
