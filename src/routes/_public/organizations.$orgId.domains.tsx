import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

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
  component: DomainsLayout,
})

function DomainsLayout() {
  return <Outlet />
}
