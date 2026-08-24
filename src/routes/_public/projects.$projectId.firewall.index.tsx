import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'
import {
  parseFirewallListSearch,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import { getFirewallLastResourceRedirectSearch } from '@/lib/firewall/last-resource'
import { getConsoleAccountFromSingleton } from '@/lib/console-account-get'
import type { UserPrefs } from '@/lib/user-prefs-keys'
import { pageTitle } from '@/lib/utils/page-title'

export type FirewallListSearch = {
  resourceType?: FirewallResourceType
  resourceId?: string
}

export const Route = createFileRoute('/_public/projects/$projectId/firewall/')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  validateSearch: (search: Record<string, unknown>): FirewallListSearch =>
    parseFirewallListSearch(search) ?? {},
  beforeLoad: ({ params, search }) => {
    const lastSearch = getFirewallLastResourceRedirectSearch(
      getConsoleAccountFromSingleton()?.prefs as UserPrefs | undefined,
      params.projectId,
      search,
    )
    if (!lastSearch) return
    throw redirect({
      to: '/projects/$projectId/firewall',
      params: { projectId: params.projectId },
      search: lastSearch,
      replace: true,
    })
  },
  component: View,
})
