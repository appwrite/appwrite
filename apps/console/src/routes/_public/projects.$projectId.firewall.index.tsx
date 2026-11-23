import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  firewallTabPath,
  resolveFirewallListSearch,
} from '@/lib/firewall/conditions'

/**
 * Firewall rules live in a Firewall tab on each function and site, and in
 * project settings for the API. Keep old links working by opening the tab
 * for the scope they name.
 */
export const Route = createFileRoute('/_public/projects/$projectId/firewall/')({
  beforeLoad: ({ params, search }) => {
    throw redirect({
      to: firewallTabPath(
        params.projectId,
        resolveFirewallListSearch(search as Record<string, unknown>),
      ),
      replace: true,
    })
  },
})
