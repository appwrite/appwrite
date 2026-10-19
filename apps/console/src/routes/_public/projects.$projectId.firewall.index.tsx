import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/firewall/View'
import {
  parseFirewallListSearch,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import { pageTitle } from '@/lib/utils/page-title'

export type FirewallListSearch = {
  resourceType?: FirewallResourceType
  resourceId?: string
}

export const Route = createFileRoute('/_public/projects/$projectId/firewall/')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  validateSearch: (search: Record<string, unknown>): FirewallListSearch =>
    parseFirewallListSearch(search) ?? {},
  component: View,
})
