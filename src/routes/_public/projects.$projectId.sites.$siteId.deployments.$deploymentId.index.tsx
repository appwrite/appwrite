import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/deployments/$deploymentId/View'
import {
  siteQueryOptions,
  siteDeploymentQueryOptions,
  siteDeploymentsQueryOptions,
  deploymentProxyRulesQueryOptions,
} from '@/lib/react-query/hooks/sites'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments/$deploymentId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId, deploymentId } = params
    const { queryClient } = context

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch site data - blocks navigation until ready
      queryClient.ensureQueryData(siteQueryOptions(projectId, siteId)),
      // Fetch deployment data - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDeploymentQueryOptions(projectId, siteId, deploymentId),
      ),
      // Fetch deployments list for navigation (previous/next) - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDeploymentsQueryOptions(projectId, siteId, 0, 1000),
      ),
      // Fetch proxy rules for this deployment - blocks navigation until ready
      queryClient.ensureQueryData(
        deploymentProxyRulesQueryOptions(projectId, siteId, deploymentId),
      ),
    ])
  },
  component: SiteDeploymentDetailPage,
})

function SiteDeploymentDetailPage() {
  return <View />
}
