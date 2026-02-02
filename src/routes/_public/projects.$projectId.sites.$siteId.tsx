import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { Layout } from '@/components/pages/projects/$projectId/sites/Layout'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  siteDomainsQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    try {
      // Fetch project first so setProjectRegion runs and project-scoped calls use the
      // correct regional endpoint (e.g. nyc.cloud.appwrite.io). Uses ensureQueryData
      // so no duplicate call if parent loader already fetched.
      await queryClient.ensureQueryData(projectQueryOptions(projectId))

      // Fetch site - blocks navigation until ready
      const site = await queryClient.ensureQueryData(
        siteQueryOptions(projectId, siteId),
      )

      // Fetch critical data before rendering to prevent layout shifts
      await Promise.all([
        // Fetch recent deployments (first 4)
        queryClient.ensureQueryData(
          siteDeploymentsQueryOptions(projectId, siteId, 0, 4, [
            Query.select([
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
        // Fetch production-ready deployments (for active deployment info)
        site.deploymentId
          ? queryClient.ensureQueryData(
              siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
            )
          : Promise.resolve(),
        // Fetch first page of domains
        queryClient.ensureQueryData(
          siteDomainsQueryOptions(projectId, siteId, 0, 25, ''),
        ),
      ])
    } catch (error) {
      // Don't throw - let the component handle the error (e.g. on reload when
      // session isn't ready yet or network fails). The View will show
      // loading/error state via hooks and can retry.
      console.warn('Failed to fetch site data in loader:', error)
    }
  },
  component: SiteLayoutPage,
})

function SiteLayoutPage() {
  const matches = useMatches()

  // Check if we're on a deployment detail route (should not have site tabs)
  const isDeploymentDetailRoute = matches.some(
    (match) =>
      match.routeId.includes('/deployments/$deploymentId') ||
      match.routeId ===
        '/_public/projects/$projectId/sites/$siteId/deployments/$deploymentId' ||
      match.routeId ===
        '/_public/projects/$projectId/sites/$siteId/deployments/$deploymentId/' ||
      match.routeId.startsWith(
        '/_public/projects/$projectId/sites/$siteId/deployments/$deploymentId',
      ),
  )

  if (isDeploymentDetailRoute) {
    // For deployment detail routes, render outlet directly (they have their own layout)
    return <Outlet />
  }

  // For other routes, render Layout which provides tabs
  return <Layout />
}
