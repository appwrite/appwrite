import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/VerifyDomain'
import { siteQueryOptions, projectQueryOptions } from '@/lib/react-query/hooks'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { sdk } from '@/lib/appwrite/sdk'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/add-domain/verify/$domain',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.site?.name ?? loaderData?.site?.resourceId ?? 'Site',
          'Sites',
        ),
      },
    ],
  }),
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Get ruleId from URL query parameter
    const urlParams = new URLSearchParams(location.search)
    const ruleId = urlParams.get('ruleId')

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    const projectData = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    // Fetch site - blocks navigation until ready
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

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
