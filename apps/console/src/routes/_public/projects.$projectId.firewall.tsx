import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { isCloudProfile } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/projects/$projectId/firewall')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  component: FirewallLayout,
})

function FirewallLayout() {
  return <Outlet />
}
