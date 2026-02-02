import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/AddDomain'
import {
  siteQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { fetchVcsInstallations } from '@/lib/react-query/hooks/vcs'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/domains/add-domain',
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
    const site = await queryClient.ensureQueryData(
      siteQueryOptions(projectId, siteId),
    )

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch existing deployment rules
      queryClient.ensureQueryData({
        queryKey: ['proxy-rules', 'deployment', 'manual', projectId],
        queryFn: async () => {
          const projectSdk = sdk.forProject(projectId)
          const response = await projectSdk.proxy.listRules({
            queries: [
              Query.equal('type', 'deployment'),
              Query.equal('trigger', 'manual'),
            ],
          })
          return {
            rules: response.rules || [],
            total: response.total || 0,
          }
        },
        staleTime: 5 * 60 * 1000,
      }),
      // Fetch VCS installations
      queryClient.ensureQueryData({
        queryKey: ['vcs', 'installations', projectId, 0, 25],
        queryFn: () => fetchVcsInstallations(projectId, 0, 25),
        staleTime: 5 * 60 * 1000,
      }),
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
      // Fetch repository branches if site has installationId and providerRepositoryId
      site.installationId && site.providerRepositoryId
        ? queryClient
            .ensureQueryData({
              queryKey: [
                'vcs',
                'branches',
                projectId,
                site.installationId,
                site.providerRepositoryId,
              ],
              queryFn: async () => {
                const projectSdk = sdk.forProject(projectId)
                const response =
                  await projectSdk.vcs.listRepositoryBranches({
                    installationId: site.installationId!,
                    providerRepositoryId: site.providerRepositoryId!,
                  })
                return {
                  branches: response.branches || [],
                  total: response.total || 0,
                }
              },
              staleTime: 5 * 60 * 1000,
            })
            .catch(() => {
              // Ignore errors - branches might not be available
            })
        : Promise.resolve(),
    ])
  },
  component: AddDomainPage,
})

function AddDomainPage() {
  return <View />
}
