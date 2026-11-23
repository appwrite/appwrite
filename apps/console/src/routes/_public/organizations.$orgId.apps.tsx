import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { isCloudProfile } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/organizations/$orgId/apps')({
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  component: OrgAppsLayout,
})

function OrgAppsLayout() {
  return <Outlet />
}
