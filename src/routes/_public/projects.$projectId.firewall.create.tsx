import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/create/View'
import { parseFirewallResourceTypeSearch } from '@/lib/firewall/conditions'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/firewall/create',
)({
  head: () => ({ meta: [{ title: pageTitle('Create firewall rule') }] }),
  validateSearch: (search: Record<string, unknown>) => {
    const resourceType = parseFirewallResourceTypeSearch(search.resourceType)
    return resourceType ? { resourceType } : {}
  },
  codeSplitGroupings: [],
  component: View,
})
