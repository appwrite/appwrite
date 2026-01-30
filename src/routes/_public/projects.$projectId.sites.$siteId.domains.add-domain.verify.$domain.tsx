import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/VerifyDomain'
import {
  siteQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { sdk } from '@/lib/appwrite/sdk'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/add-domain/verify/$domain',
)({
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId, domain } = params
    const { queryClient } = context

    // Get ruleId from URL query parameter
    const urlParams = new URLSearchParams(location.search)
    const ruleId = urlParams.get('ruleId')

    // Fetch site first - blocks navigation until ready
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

    // Fetch project data (needed for header/sidebar) - blocks navigation
    const projectData = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch proxy rule if ruleId is provided
      ruleId
        ? queryClient.ensureQueryData({
            queryKey: ['proxy-rule', 'project', projectId, ruleId],
            queryFn: async () => {
              const projectSdk = sdk.forProject(projectId)
              return await projectSdk.proxy.getRule({ ruleId })
            },
            staleTime: 5 * 60 * 1000,
          })
        : Promise.resolve(),
      // Fetch organization domains (cloud only)
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
  component: VerifyDomainPage,
})

function VerifyDomainPage() {
  return <View />
}
