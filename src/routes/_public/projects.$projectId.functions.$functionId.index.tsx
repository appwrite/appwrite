import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Deployments'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionDeploymentsQueryOptions,
  functionDeploymentQueryOptions,
  functionDomainsQueryOptions,
  projectRuntimesQueryOptions,
  functionSpecificationsQueryOptions,
  DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { SpecificationType } from '@/lib/specifications'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema, parseListSearch } from '@/lib/table-filters'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  validateSearch: listSearchSchema,
  loader: async ({ params, context, search: routeSearch }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    const { page, filterQueries } = parseListSearch(routeSearch, {
      page: 1,
      limit: DEFAULT_PAGE_SIZE,
    })
    const pageIndex = page - 1
    const hasFilterQuery = !!filterQueries?.length

    // Fetch function to get deploymentId - blocks navigation until ready
    const func = await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )

    const deploymentsPromise = hasFilterQuery
      ? Promise.resolve(undefined)
      : queryClient.ensureQueryData(
          functionDeploymentsQueryOptions(
            projectId,
            functionId,
            pageIndex,
            DEFAULT_PAGE_SIZE,
          ),
        )

    const criticalPromises: Promise<unknown>[] = [
      deploymentsPromise,
      // Fetch domains/rules (for overview card) - use same params as domains tab to share cache
      queryClient.ensureQueryData(
        functionDomainsQueryOptions(
          projectId,
          functionId,
          0,
          DOMAINS_DEFAULT_PAGE_SIZE,
          '',
        ),
      ),
      // Fetch runtimes (for runtime name display) - blocks navigation until ready
      queryClient.ensureQueryData(projectRuntimesQueryOptions(projectId)),
      // Fetch specifications (for resource limits) - blocks navigation until ready
      queryClient.ensureQueryData(
        functionSpecificationsQueryOptions(
          projectId,
          SpecificationType.Runtimes,
        ),
      ),
    ]

    // Fetch active deployment if function has a deploymentId - blocks navigation until ready
    if (func?.deploymentId) {
      criticalPromises.push(
        queryClient.ensureQueryData(
          functionDeploymentQueryOptions(
            projectId,
            functionId,
            func.deploymentId,
          ),
        ),
      )
    }

    await Promise.all(criticalPromises)

    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: View,
})
