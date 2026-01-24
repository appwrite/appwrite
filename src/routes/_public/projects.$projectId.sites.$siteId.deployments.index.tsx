import { createFileRoute } from '@tanstack/react-router'
import { SiteDeploymentsView } from '@/components/pages/projects/$projectId/sites/SiteDeployments'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
import { fetchVcsInstallations } from '@/lib/react-query/hooks/vcs'
import { Query } from '@appwrite.io/console'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments/',
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
    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch first page of deployments - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDeploymentsQueryOptions(projectId, siteId, 0, DEFAULT_PAGE_SIZE, [
          Query.select([
            'buildSize',
            'sourceSize',
            'totalSize',
            'buildDuration',
            'status',
            'type',
            'resourceId',
            'providerRepositoryUrl',
            'providerRepositoryOwner',
            'providerRepositoryName',
            'providerBranchUrl',
            'providerBranch',
            'providerCommitMessage',
            'providerCommitHash',
            'providerCommitUrl',
          ]),
        ]),
      ),
      // Fetch active deployment if available
      site.deploymentId
        ? queryClient.ensureQueryData(
            siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
          )
        : Promise.resolve(),
      // Fetch VCS installations (for deployment actions)
      queryClient.ensureQueryData({
        queryKey: ['vcs', 'installations', projectId, 0, 25],
        queryFn: () => fetchVcsInstallations(projectId, 0, 25),
        staleTime: 5 * 60 * 1000,
      }),
    ])
  },
  component: SiteDeploymentsPage,
})

function SiteDeploymentsPage() {
  return <SiteDeploymentsView />
}
