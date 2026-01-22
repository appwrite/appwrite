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

    // Prefetch organizations list (non-critical, for dropdowns) - doesn't block
    queryClient.prefetchQuery(organizationsQueryOptions()).catch(() => {
      // Don't block on optional data
    })

    // Fetch critical page-specific data before rendering to prevent layout shifts
    // Note: Organization plan is already loaded in parent route's loader
    if (orgId) {
      await Promise.all([
        // Fetch first page of projects - blocks navigation until ready
        queryClient.ensureQueryData(
          activeProjectsQueryOptions(orgId, 0, DEFAULT_PAGE_SIZE, ''),
        ),
        // Fetch first page of memberships - blocks navigation until ready
        queryClient.ensureQueryData(
          organizationMembershipsQueryOptions(orgId, 0, DEFAULT_PAGE_SIZE, ''),
        ),
      ])
    }
  },
  component: OrgOverviewIndexPage,
})

// Index route doesn't need to render anything - parent OrgOverview handles the content
function OrgOverviewIndexPage() {
  return null
}
