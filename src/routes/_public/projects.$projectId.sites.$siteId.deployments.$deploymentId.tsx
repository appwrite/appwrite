import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/deployments/$deploymentId/View'
import {
  siteQueryOptions,
  siteDeploymentQueryOptions,
  deploymentProxyRulesQueryOptions,
} from '@/lib/react-query/hooks/sites'
import { projectQueryOptions } from '@/lib/react-query/hooks'
export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments/$deploymentId',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId, deploymentId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Fetch site so we know the active deployment id
    const site = await queryClient.ensureQueryData(
      siteQueryOptions(projectId, siteId),
    )

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch deployment data - blocks navigation until ready
      queryClient.ensureQueryData(
        siteDeploymentQueryOptions(projectId, siteId, deploymentId),
      ),
      // Fetch proxy rules for this deployment - blocks navigation until ready
      queryClient.ensureQueryData(
        deploymentProxyRulesQueryOptions(projectId, siteId, deploymentId),
      ),
      // Fetch active deployment so it stays in sync when viewing deployment details
      site?.deploymentId
        ? queryClient.ensureQueryData(
            siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
          )
        : Promise.resolve(),
    ])
  },
  component: SiteDeploymentDetailPage,
})

function SiteDeploymentDetailPage() {
  return <View />
}
