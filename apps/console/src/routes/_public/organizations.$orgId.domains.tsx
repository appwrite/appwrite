import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { canAccessOrganizationDomains } from '@/lib/console-rbac-loader'

export const Route = createFileRoute('/_public/organizations/$orgId/domains')({
  // beforeLoad runs before child loaders, so a redirect here prevents the
  // domains list request from ever being sent on profiles without the API.
  beforeLoad: async ({ params, context }) => {
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
