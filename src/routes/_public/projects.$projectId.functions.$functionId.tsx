import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { FunctionLayout } from '@/components/pages/projects/$projectId/functions/FunctionLayout'
import { fetchProjectFunction } from '@/lib/react-query/hooks'

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
    await queryClient.fetchQuery({
      queryKey: ['function', 'project', projectId, functionId],
      queryFn: () => fetchProjectFunction(projectId, functionId),
      staleTime: 30 * 1000,
    })
  },
  component: FunctionLayoutWrapper,
})

function FunctionLayoutWrapper() {
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

  // For other routes, render FunctionLayout which provides tabs
  return <FunctionLayout />
}
