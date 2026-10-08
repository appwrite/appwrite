import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  siteQueryOptions,
  siteDomainsQueryOptions,
  projectQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    // Fetch site - blocks navigation until ready
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

    // Fetch critical data before rendering to prevent layout shifts
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await Promise.all([
      // Fetch first page of domains - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDomainsQueryOptions(
          projectId,
          siteId,
          0,
          DOMAINS_DEFAULT_PAGE_SIZE,
          '',
        ),
      ),
      // Fetch organization domains (for verification status) - cloud only
      projectData?.teamId
        ? queryClient
            .ensureQueryData(
              organizationDomainsQueryOptions(projectData.teamId),
            )
            .catch(() => {
              // Ignore errors - domains API might not be available in self-hosted
            })
            : Promise.resolve(),
    ])
  },
  component: SiteDomainsLayout,
})

function SiteDomainsLayout() {
  return <Outlet />
}
