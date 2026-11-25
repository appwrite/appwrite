import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  organizationMembershipsQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/members',
)({
  head: () => ({ meta: [{ title: pageTitle('Members', 'Organization') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { orgId } = params
    const { queryClient } = context

    // The first page OrgOverview shows: the org layout has usually loaded it.
    await Promise.all([
      queryClient.ensureQueryData(organizationsQueryOptions()),
      orgId
        ? queryClient.ensureQueryData(
            organizationMembershipsQueryOptions(
              orgId,
              0,
              GRID_DEFAULT_PAGE_SIZE,
              '',
            ),
          )
        : undefined,
    ])
  },
  component: MembersPage,
})

function MembersPage() {
  return null
}
