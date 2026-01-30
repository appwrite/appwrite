import { View } from '@/components/pages/organizations/$orgId/domains/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  organizationsQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'

const DOMAINS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/domains/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery(organizationsQueryOptions())

    // Fetch domains for the organization (initial page, no search)
    if (orgId) {
      // Fetch first page of domains - blocks navigation until ready
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
