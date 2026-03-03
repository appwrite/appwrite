import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/functions/Deployments'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionDeploymentsQueryOptions,
  functionDeploymentQueryOptions,
  functionDomainsQueryOptions,
  projectRuntimesQueryOptions,
  functionSpecificationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const DEPLOYMENTS_PER_PAGE = 25
const DOMAINS_LIMIT = 25 // Align with domains tab to share cache; overview card shows 3

const searchSchema = z.object({
  page: z.number().int().min(1).optional().catch(undefined),
})

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
  validateSearch: searchSchema,
  loader: async ({ params, context, location }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Parse page from URL search params as fallback
    const urlParams = new URLSearchParams(location.search)
    const pageParam = urlParams.get('page')
    const page = pageParam ? Math.max(1, parseInt(pageParam, 10)) : 1
    const pageIndex = page - 1 // Convert 1-indexed to 0-indexed

    // Fetch function to get deploymentId - blocks navigation until ready
    const func = await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )

    // Fetch critical data before rendering to prevent layout shifts
    // All data is prefetched using queryOptions to prevent duplicate API calls
    const criticalPromises: Promise<unknown>[] = [
      // Fetch deployments list for the requested page - blocks navigation until ready
      queryClient.ensureQueryData(
        functionDeploymentsQueryOptions(
          projectId,
          functionId,
          pageIndex,
          DEPLOYMENTS_PER_PAGE,
        ),
      ),
      // Fetch domains/rules (for overview card) - use same params as domains tab to share cache
      queryClient.ensureQueryData(
        functionDomainsQueryOptions(projectId, functionId, 0, DOMAINS_LIMIT, ''),
      ),
      // Fetch runtimes (for runtime name display) - blocks navigation until ready
      queryClient.ensureQueryData(projectRuntimesQueryOptions(projectId)),
      // Fetch specifications (for resource limits) - blocks navigation until ready
      queryClient.ensureQueryData(
        functionSpecificationsQueryOptions(projectId),
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
