import { createFileRoute, redirect } from '@tanstack/react-router'
import { canAccessProjectAgentConnect } from '@/lib/console-rbac-loader'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import { resolveProjectRootLanding } from '@/lib/project-landing'
import {
  consoleAccountQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import type { UserPrefs } from '@/lib/user-prefs-keys'

export const Route = createFileRoute('/_public/projects/$projectId/')({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    // Keep root mounted so budget/plan curtains can render without looping
    // through nested `/overview` redirects in the project layout.
    if (context.budgetLimitReached || context.planUsageLimitReached) {
      return undefined
    }

    if (!loadDebugOverrides().showProjectAgents) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params: { projectId },
        replace: true,
      })
    }

    const [project, account] = await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(consoleAccountQueryOptions()).catch(() => null),
    ])

    const canAccessAgents = await canAccessProjectAgentConnect(
      queryClient,
      projectId,
    )
    const landing = resolveProjectRootLanding({
      project,
      prefs: account?.prefs as UserPrefs | undefined,
      projectId,
      canAccessAgents,
    })

    throw redirect({
      to:
        landing === 'agents'
          ? '/projects/$projectId/agents'
          : '/projects/$projectId/overview',
      params: { projectId },
      replace: true,
    })
  },
  component: ProjectRootRedirect,
})

function ProjectRootRedirect() {
  return null
}
