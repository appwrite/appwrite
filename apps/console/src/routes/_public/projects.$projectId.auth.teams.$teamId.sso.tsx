import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/auth/teams/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  organizationPlanQueryOptions,
  projectAddonsQueryOptions,
  projectQueryOptions,
  ssoDomainsQueryOptions,
  ssoProvidersQueryOptions,
} from '@/lib/react-query/hooks'
import { fetchTeam } from '@/lib/react-query/hooks/users'
import type { SSOTarget } from '@/lib/sso-target'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/auth/teams/$teamId/sso',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().sso) {
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
        // The router types this loader's data as `never`, like its siblings'
        title: pageTitle(
          (loaderData as { team?: { name?: string } } | undefined)?.team
            ?.name ?? 'Team',
          'Auth',
        ),
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
      const project = await queryClient.ensureQueryData(
        projectQueryOptions(projectId),
      )
      const target: SSOTarget = { scope: 'project', projectId, teamId }
      await Promise.all([
        // Fetch team - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: ['team', 'project', projectId, teamId],
          queryFn: () => fetchTeam(projectId, teamId),
          staleTime: 30 * 1000,
        }),
        // The team's single sign-on, and whether the project's add-on allows
        // setting it up: the page picks its cards from them.
        queryClient
          .ensureQueryData(ssoDomainsQueryOptions(target))
          .catch(() => undefined),
        queryClient
          .ensureQueryData(ssoProvidersQueryOptions(target))
          .catch(() => undefined),
        project?.teamId
          ? queryClient
              .ensureQueryData(organizationPlanQueryOptions(project.teamId))
              .catch(() => undefined)
          : undefined,
        getActiveProfileFeatures().billing
          ? queryClient
              .ensureQueryData(projectAddonsQueryOptions(projectId))
              .catch(() => undefined)
          : undefined,
      ])
      const team = queryClient.getQueryData<
        Awaited<ReturnType<typeof fetchTeam>>
      >(['team', 'project', projectId, teamId])
      return { team }
    }
  },
  component: TeamSingleSignOnPage,
})

function TeamSingleSignOnPage() {
  const { projectId, teamId } = Route.useParams()
  return <View key={`team-${projectId}-${teamId}`} />
}
