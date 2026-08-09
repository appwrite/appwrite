import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Deployments'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  deploymentProxyRulesQueryOptions,
  projectQueryOptions,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'

const DEPLOYMENTS_SELECT = [
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
    'providerCommitAuthor',
    'providerCommitAuthorUrl',
    'screenshotDark',
    'screenshotLight',
    '$createdAt',
  ]),
]

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/',
)({
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
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

      const { page, filterQueries } = parseListSearch(routeSearch, {
        page: 1,
        limit: DEFAULT_PAGE_SIZE,
      })
      const pageIndex = page - 1
      const hasFilterQuery = !!filterQueries?.length

      // Fetch site to get deploymentId - blocks navigation until ready
      const site = await queryClient.ensureQueryData(
        siteQueryOptions(projectId, siteId),
      )

      // Prefetch deployments only when no filters (avoids duplicate request when filters applied)
      const deploymentsPromise = hasFilterQuery
        ? Promise.resolve(undefined)
        : queryClient.ensureQueryData(
            siteDeploymentsQueryOptions(
              projectId,
              siteId,
              pageIndex,
              DEFAULT_PAGE_SIZE,
              DEPLOYMENTS_SELECT,
            ),
          )

      // Fetch critical data before rendering to prevent layout shifts
      const criticalPromises: Promise<unknown>[] = [deploymentsPromise]

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
