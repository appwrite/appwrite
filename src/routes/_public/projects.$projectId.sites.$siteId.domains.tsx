import { createFileRoute } from '@tanstack/react-router'
import { SiteDomainsView } from '@/components/pages/projects/$projectId/sites/SiteDomains'
import {
  siteQueryOptions,
  siteDomainsQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
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
    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch first page of domains - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDomainsQueryOptions(projectId, siteId, 0, DEFAULT_PAGE_SIZE, ''),
      ),
      // Fetch organization domains (for verification status) - cloud only
      projectData?.teamId
        ? queryClient
            .ensureQueryData({
              queryKey: ['domains', 'organization', projectData.teamId],
              queryFn: () => fetchOrganizationDomains(projectData.teamId),
              staleTime: 5 * 60 * 1000,
            })
            .catch(() => {
              // Ignore errors - domains API might not be available in self-hosted
            })
        : Promise.resolve(),
    ])
  },
  component: SiteDomainsPage,
})

function SiteDomainsPage() {
  return <SiteDomainsView />
}
