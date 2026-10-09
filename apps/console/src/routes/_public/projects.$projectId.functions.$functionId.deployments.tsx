import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionDeploymentsQueryOptions,
  functionDeploymentQueryOptions,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { ensureQueryDataIfFound } from '@/lib/react-query/ensure-query-data-if-found'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'
import { getRedeploySourceDeploymentId } from '@/lib/utils/deployment-status'

function isDeploymentsListPath(pathname: string) {
  return pathname.endsWith('/deployments') || pathname.endsWith('/deployments/')
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/deployments',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const func = await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )

    if (!isDeploymentsListPath(location.pathname)) {
      return { function: func }
    }

    const { page, filterQueries } = parseListSearch(routeSearch, {
      page: 1,
      limit: DEFAULT_PAGE_SIZE,
    })
    const pageIndex = page - 1
    const hasFilterQuery = !!filterQueries?.length

    const criticalPromises: Promise<unknown>[] = [
      hasFilterQuery
        ? Promise.resolve(undefined)
        : queryClient.ensureQueryData(
            functionDeploymentsQueryOptions(
              projectId,
              functionId,
              pageIndex,
              DEFAULT_PAGE_SIZE,
            ),
          ),
    ]

    if (func?.deploymentId) {
      criticalPromises.push(
        ensureQueryDataIfFound(
          queryClient,
          functionDeploymentQueryOptions(
            projectId,
            functionId,
            func.deploymentId,
          ),
        ),
      )
    }

    const redeployDeploymentId = getRedeploySourceDeploymentId(func)
    if (redeployDeploymentId && redeployDeploymentId !== func?.deploymentId) {
      criticalPromises.push(
        ensureQueryDataIfFound(
          queryClient,
          functionDeploymentQueryOptions(
            projectId,
            functionId,
            redeployDeploymentId,
          ),
        ),
      )
    }

    await Promise.all(criticalPromises)

    return { function: func }
  },
  component: DeploymentsLayoutPage,
})

function DeploymentsLayoutPage() {
  return <Outlet />
}
