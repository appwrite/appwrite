import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { prefetchFirewallTabData } from '@/lib/react-query/hooks'
import { isCloudProfile } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/firewall',
)({
  head: () => ({ meta: [{ title: pageTitle('Firewall', 'Settings') }] }),
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/settings',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    await prefetchFirewallTabData(context.queryClient, params.projectId, {
      resourceType: 'api',
    })
  },
  component: SettingsFirewallPage,
})

function SettingsFirewallPage() {
  return <View />
}
