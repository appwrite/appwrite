import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  siteQueryOptions,
  siteDeploymentsQueryOptions,
  siteDeploymentQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { ensureQueryDataIfFound } from '@/lib/react-query/ensure-query-data-if-found'
import { fetchVcsInstallations } from '@/lib/react-query/hooks/vcs'
import { Query } from '@appwrite.io/console'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
import { getRedeploySourceDeploymentId } from '@/lib/utils/deployment-status'

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

function isDeploymentsListPath(pathname: string) {
  return pathname.endsWith('/deployments') || pathname.endsWith('/deployments/')
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments',
)({
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    if (!isDeploymentsListPath(location.pathname)) {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    const { page, filterQueries } = parseListSearch(routeSearch, {
      page: 1,
      limit: DEFAULT_PAGE_SIZE,
    })
    const pageIndex = page - 1
    const hasFilterQuery = !!filterQueries?.length

    const site = await queryClient.ensureQueryData(
      siteQueryOptions(projectId, siteId),
    )
    const redeployDeploymentId = getRedeploySourceDeploymentId(site)

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

    await Promise.all([
      deploymentsPromise,
      site.deploymentId
        ? ensureQueryDataIfFound(
            queryClient,
            siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
          )
        : Promise.resolve(),
      redeployDeploymentId && redeployDeploymentId !== site.deploymentId
        ? ensureQueryDataIfFound(
            queryClient,
            siteDeploymentQueryOptions(projectId, siteId, redeployDeploymentId),
          )
        : Promise.resolve(),
      queryClient.ensureQueryData({
        queryKey: ['vcs', 'installations', projectId, 0, 10],
        queryFn: () => fetchVcsInstallations(projectId, 0, 10),
        staleTime: 5 * 60 * 1000,
      }),
    ])
  },
  component: DeploymentsLayoutPage,
})

function DeploymentsLayoutPage() {
  return <Outlet />
}
