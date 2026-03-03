import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canAccessOrganizationDomains } from '@/lib/console-rbac-loader'

export const Route = createFileRoute('/_public/organizations/$orgId/domains')({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().domains) {
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { orgId } = params
    const { queryClient } = context
    if (!orgId) return
    const canAccess = await canAccessOrganizationDomains(queryClient, orgId)
    if (!canAccess) {
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId },
        replace: true,
      })
    }
  },
  component: DomainsLayout,
})

function DomainsLayout() {
  return <Outlet />
}
