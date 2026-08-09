import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Deployments'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { fetchVcsInstallations } from '@/lib/react-query/hooks/vcs'
import { Query } from '@appwrite.io/console'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
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
  '/_public/projects/$projectId/sites/$siteId/deployments/',
)({
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    const { page, filterQueries } = parseListSearch(routeSearch, {
      page: 1,
      limit: DEFAULT_PAGE_SIZE,
    })
    const pageIndex = page - 1
    const hasFilterQuery = !!filterQueries?.length

    // Fetch site - blocks navigation until ready
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
    await Promise.all([
      deploymentsPromise,
      // Fetch active deployment if available
      site.deploymentId
        ? queryClient.ensureQueryData(
            siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
          )
        : Promise.resolve(),
      // Fetch VCS installations (for deployment actions)
      queryClient.ensureQueryData({
        queryKey: ['vcs', 'installations', projectId, 0, 10],
        queryFn: () => fetchVcsInstallations(projectId, 0, 10),
        staleTime: 5 * 60 * 1000,
      }),
    ])
  },
  component: SiteDeploymentsPage,
})

function SiteDeploymentsPage() {
  return <View />
}
