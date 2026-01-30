import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { Layout } from '@/components/pages/projects/$projectId/functions/Layout'
import { projectFunctionQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context

    // Fetch function data (needed for layout) - blocks navigation
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )
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
