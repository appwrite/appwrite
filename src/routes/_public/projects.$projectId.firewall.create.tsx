import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/create/View'
import { parseFirewallResourceTypeSearch } from '@/lib/firewall/conditions'
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
    const query = parseUsageFilterQuerySearch(search.query)
    return {
      ...(resourceType ? { resourceType } : {}),
      ...(query ? { query } : {}),
    }
  },
  codeSplitGroupings: [],
  component: View,
})
