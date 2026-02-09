import { createFileRoute } from '@tanstack/react-router'
import {
  organizationMembershipsQueryOptions,
  organizationsQueryOptions,
  activeProjectsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute('/_public/organizations/$orgId/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Block until all data needed for the projects tab is ready so the page doesn't
    // render with empty list or "Loading...". Overview needs organizations (for selectedOrg),
    // projects, and memberships before first paint.
    await Promise.all([
      queryClient.ensureQueryData(organizationsQueryOptions()),
      ...(orgId
        ? [
            queryClient.ensureQueryData(
              activeProjectsQueryOptions(orgId, 0, DEFAULT_PAGE_SIZE, ''),
            ),
            queryClient.ensureQueryData(
              organizationMembershipsQueryOptions(
                orgId,
                0,
                DEFAULT_PAGE_SIZE,
                '',
              ),
            ),
          ]
        : []),
    ])
  },
  component: OrgOverviewIndexPage,
})

// Index route doesn't need to render anything - parent OrgOverview handles the content
function OrgOverviewIndexPage() {
  return null
}
