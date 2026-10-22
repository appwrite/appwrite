import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  fetchOrganizationMemberships,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks'

const MEMBERSHIPS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/members',
)({
  head: () => ({ meta: [{ title: pageTitle('Members', 'Organization') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { orgId } = params
    const { queryClient } = context

    await queryClient.prefetchQuery(organizationsQueryOptions())

    if (orgId) {
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
        staleTime: 30 * 1000,
      })
    }
  },
  component: MembersPage,
})

function MembersPage() {
  return null
}
