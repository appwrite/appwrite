import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  fetchOrganizationMemberships,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks'

const MEMBERSHIPS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/members')({
  head: () => ({ meta: [{ title: pageTitle('Members', 'Organization') }] }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery(organizationsQueryOptions())

    // Fetch memberships for the organization (initial page, no search)
    if (orgId) {
      // Fetch first page of memberships - blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: [
          'memberships',
          'organization',
          orgId,
          0,
          MEMBERSHIPS_PER_PAGE,
          '',
        ],
        queryFn: () =>
          fetchOrganizationMemberships(orgId, 0, MEMBERSHIPS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: MembersPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function MembersPage() {
  return null
}
