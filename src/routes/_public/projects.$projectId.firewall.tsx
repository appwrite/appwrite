import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  firewallRulesQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute('/_public/projects/$projectId/firewall')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().firewall) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    await queryClient.ensureQueryData(
      firewallRulesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, undefined),
    )
  },
  component: FirewallLayout,
})

function FirewallLayout() {
  return <Outlet />
}
