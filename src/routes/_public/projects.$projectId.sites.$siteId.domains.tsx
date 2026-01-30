import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Domains'
import {
  siteQueryOptions,
  siteDomainsQueryOptions,
  projectQueryOptions,
  organizationDomainsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

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

    // Fetch site first - blocks navigation until ready
    const site = await queryClient.ensureQueryData(
      siteQueryOptions(projectId, siteId),
    )

    // Fetch project data (needed for header/sidebar) - blocks navigation
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )

    // Fetch critical data before rendering to prevent layout shifts
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await Promise.all([
      // Fetch first page of domains - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDomainsQueryOptions(projectId, siteId, 0, DEFAULT_PAGE_SIZE, ''),
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
  component: SiteDomainsPage,
})

function SiteDomainsPage() {
  return <View />
}
