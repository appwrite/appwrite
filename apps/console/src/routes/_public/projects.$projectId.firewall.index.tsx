import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'
import { prefetchFirewallTabData } from '@/lib/react-query/hooks'
import {
  firewallTabPath,
  parseFirewallListSearch,
} from '@/lib/firewall/conditions'

export const Route = createFileRoute('/_public/projects/$projectId/firewall/')({
  beforeLoad: ({ params, search }) => {
    // Old links name a function or site in the search: open its Firewall tab.
    const selection = parseFirewallListSearch(search as Record<string, unknown>)
    if (selection && selection.resourceType !== 'api') {
      throw redirect({
        to: firewallTabPath(params.projectId, selection),
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
  component: FirewallApiPage,
})

function FirewallApiPage() {
  return <View tab="api" />
}
