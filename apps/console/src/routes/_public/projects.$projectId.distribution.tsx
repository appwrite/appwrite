import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canAccessDistribution } from '@/lib/console-rbac-loader'
import {
  consoleVariablesQueryOptions,
  isDistributionEnabled,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/distribution',
)({
  head: () => ({ meta: [{ title: pageTitle('Distribution') }] }),
  beforeLoad: async ({ params, context }) => {
    // Feature overrides live in localStorage, so only the client can resolve them.
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    const toProject = redirect({
      to: '/projects/$projectId',
      params: { projectId },
      replace: true,
    })

    if (!getActiveProfileFeatures().distribution) throw toProject

    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const variables = await queryClient
      .ensureQueryData(consoleVariablesQueryOptions(project?.region))
      .catch(() => undefined)
    if (!isDistributionEnabled(variables)) throw toProject

    if (!(await canAccessDistribution(queryClient, projectId))) throw toProject
  },
  component: Outlet,
})
