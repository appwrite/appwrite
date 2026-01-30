import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/deployments/$deploymentId/View'
import {
  projectFunctionQueryOptions,
  functionDeploymentQueryOptions,
  functionDeploymentsQueryOptions,
  functionExecutionsQueryOptions,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/deployments/$deploymentId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId, deploymentId } = params
    const { queryClient } = context

    // Fetch critical data before rendering to prevent layout shifts
    // All data is prefetched using queryOptions to prevent duplicate API calls
    await Promise.all([
      // Fetch function data - blocks navigation until ready
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      // Fetch deployment data - blocks navigation until ready
      queryClient.ensureQueryData(
        functionDeploymentQueryOptions(projectId, functionId, deploymentId),
      ),
      // Fetch deployments list for navigation (previous/next) - blocks navigation until ready
      queryClient.ensureQueryData(
        functionDeploymentsQueryOptions(projectId, functionId, 0, 1000),
      ),
      // Fetch executions count for this deployment - blocks navigation until ready
      queryClient.ensureQueryData(
        functionExecutionsQueryOptions(
          projectId,
          functionId,
          0,
          1,
          [Query.equal('deploymentId', deploymentId)],
        ),
      ),
    ])
  },
  component: DeploymentDetailPage,
})

function DeploymentDetailPage() {
  return <View />
}
