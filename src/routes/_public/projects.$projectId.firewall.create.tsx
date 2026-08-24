import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/create/View'
import {
  parseFirewallResourceIdSearch,
  parseFirewallResourceTypeSearch,
} from '@/lib/firewall/conditions'
import { getFirewallLastResourceRedirectSearch } from '@/lib/firewall/last-resource'
import { getConsoleAccountFromSingleton } from '@/lib/console-account-get'
import type { UserPrefs } from '@/lib/user-prefs-keys'
import { pageTitle } from '@/lib/utils/page-title'

function parseUsageFilterQuerySearch(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/firewall/create',
)({
  head: () => ({ meta: [{ title: pageTitle('Create firewall rule') }] }),
  validateSearch: (search: Record<string, unknown>) => {
    const resourceType = parseFirewallResourceTypeSearch(search.resourceType)
    const resourceId = parseFirewallResourceIdSearch(search.resourceId)
    const query = parseUsageFilterQuerySearch(search.query)
    return {
      ...(resourceType ? { resourceType } : {}),
      ...(resourceType &&
      resourceType !== 'api' &&
      resourceId
        ? { resourceId }
        : {}),
      ...(query ? { query } : {}),
    }
  },
  beforeLoad: ({ params, search }) => {
    const lastSearch = getFirewallLastResourceRedirectSearch(
      getConsoleAccountFromSingleton()?.prefs as UserPrefs | undefined,
      params.projectId,
      search,
    )
    if (!lastSearch) return
    const query =
      typeof search.query === 'string' ? search.query.trim() : ''
    throw redirect({
      to: '/projects/$projectId/firewall/create',
      params: { projectId: params.projectId },
      search: query ? { ...lastSearch, query } : lastSearch,
      replace: true,
    })
  },
  codeSplitGroupings: [],
  component: View,
})
