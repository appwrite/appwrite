import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/sites/Deployments'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  deploymentProxyRulesQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'

const DEPLOYMENTS_PER_PAGE = 25

const searchSchema = z.object({
  page: z.number().int().min(1).optional().catch(undefined),
})

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/',
)({
  validateSearch: searchSchema,
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    try {
      // Fetch project first so setProjectRegion runs and project-scoped calls use the
      // correct regional endpoint. Uses ensureQueryData so no duplicate call if
      // parent loader already fetched.
      await queryClient.ensureQueryData(projectQueryOptions(projectId))

      // Parse page from URL search params as fallback
      const urlParams = new URLSearchParams(location.search)
      const pageParam = urlParams.get('page')
      const page = pageParam ? Math.max(1, parseInt(pageParam, 10)) : 1
      const pageIndex = page - 1 // Convert 1-indexed to 0-indexed

      // Fetch site to get deploymentId - blocks navigation until ready
      const site = await queryClient.ensureQueryData(
        siteQueryOptions(projectId, siteId),
      )

      // Fetch critical data before rendering to prevent layout shifts
      const criticalPromises: Promise<unknown>[] = [
        // Fetch deployments list for the requested page - blocks navigation until ready
        queryClient.ensureQueryData(
          siteDeploymentsQueryOptions(
            projectId,
            siteId,
            pageIndex,
            DEPLOYMENTS_PER_PAGE,
            [
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
                '$createdAt',
              ]),
            ],
          ),
        ),
      ]

      // Fetch active deployment if site has a deploymentId - blocks navigation until ready
      if (site?.deploymentId) {
        criticalPromises.push(
          queryClient.ensureQueryData(
            siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
          ),
          queryClient.ensureQueryData(
            deploymentProxyRulesQueryOptions(
              projectId,
              siteId,
              site.deploymentId,
            ),
          ),
        )
      }

      await Promise.all(criticalPromises)
    } catch (error) {
      // Don't throw - let the component handle the error (e.g. on reload when
      // session isn't ready yet or network fails). The View will show
      // loading/error state via hooks and can retry.
      console.warn('Failed to fetch site deployments in loader:', error)
    }
  },
  component: SiteDeploymentsPage,
})

function SiteDeploymentsPage() {
  return <View />
}
