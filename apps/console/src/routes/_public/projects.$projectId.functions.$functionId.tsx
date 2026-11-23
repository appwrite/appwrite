import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { Layout } from '@/components/pages/projects/$projectId/functions/Layout'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { listSearchSchema } from '@/lib/table-filters'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId',
)({
  validateSearch: listSearchSchema,
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.function?.name ?? 'Function', 'Functions'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Fetch function data (needed for layout) - blocks navigation
    await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: LayoutWrapper,
})

function LayoutWrapper() {
  const matches = useMatches()

  // Check if we're on a deployment detail route (should not have function tabs)
  const isDeploymentDetailRoute = matches.some(
    (match) =>
      match.routeId.includes('/deployments/$deploymentId') ||
      match.routeId ===
        '/_public/projects/$projectId/functions/$functionId/deployments/$deploymentId' ||
      match.routeId.startsWith(
        '/_public/projects/$projectId/functions/$functionId/deployments/$deploymentId',
      ),
  )

  if (isDeploymentDetailRoute) {
    // For deployment detail routes, render outlet directly (they have their own layout)
    return <Outlet />
  }

  // For other routes, render Layout which provides tabs
  return <Layout />
}
