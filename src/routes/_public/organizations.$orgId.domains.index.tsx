import { View } from '@/components/pages/organizations/$orgId/domains/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  organizationsQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'

const DOMAINS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/domains/')({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { orgId } = params
    const { queryClient } = context

    // Same pattern as storage index: ensure all data before rendering. Blocks navigation.
    await queryClient.ensureQueryData(organizationsQueryOptions())
    if (orgId) {
      await queryClient.ensureQueryData(
        organizationDomainsQueryOptions(orgId, 0, DOMAINS_PER_PAGE, ''),
      )
    }
  },
  component: DomainsIndexPage,
})

function DomainsIndexPage() {
  return <View />
}
