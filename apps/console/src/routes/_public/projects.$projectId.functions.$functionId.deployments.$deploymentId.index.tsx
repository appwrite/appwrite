import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/deployments/$deploymentId/View'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionDeploymentQueryOptions,
  functionExecutionsQueryOptions,
} from '@/lib/react-query/hooks'
import { Query } from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/functions/$functionId/deployments/$deploymentId/',
)({
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

    const { projectId, functionId, deploymentId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      queryClient.ensureQueryData(
        projectFunctionQueryOptions(projectId, functionId),
      ),
      // Fetch deployment data - blocks navigation until ready
      queryClient.ensureQueryData(
        functionDeploymentQueryOptions(projectId, functionId, deploymentId),
      ),
      // Fetch executions count for this deployment - blocks navigation until ready
      queryClient.ensureQueryData(
        functionExecutionsQueryOptions(projectId, functionId, 0, 1, [
          Query.equal('deploymentId', deploymentId),
        ]),
      ),
    ])
    const fn = queryClient.getQueryData<{ name?: string }>(
      projectFunctionQueryOptions(projectId, functionId).queryKey,
    )
    return { function: fn }
  },
  component: DeploymentDetailPage,
})

function DeploymentDetailPage() {
  return <View />
}
