import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/agents/View'
import { canAccessProjectAgentConnect } from '@/lib/console-rbac-loader'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import {
  accountConsentsQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/agents')({
  head: () => ({ meta: [{ title: pageTitle('Agents') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    const canAccess =
      loadDebugOverrides().showProjectAgents &&
      (await canAccessProjectAgentConnect(queryClient, projectId))
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params: { projectId },
        replace: true,
      })
    }

    await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(accountConsentsQueryOptions()),
    ])

    return undefined
  },
  component: View,
})
