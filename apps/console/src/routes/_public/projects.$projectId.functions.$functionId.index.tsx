import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/Overview'
import {
  projectQueryOptions,
  projectFunctionQueryOptions,
  functionDeploymentQueryOptions,
  functionDeploymentsQueryOptions,
  functionSpecificationsQueryOptions,
  firewallRulesQueryOptions,
  firewallTrafficOverviewQueryOptions,
  functionExecutionsForFunctionChartQueryOptions,
  functionGbHoursForFunctionChartQueryOptions,
  requestsForResourceChartQueryOptions,
  bandwidthForResourceChartQueryOptions,
  DEFAULT_USAGE_CHART_INTERVAL,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { OVERVIEW_DEPLOYMENTS_LIMIT } from '@/components/pages/projects/$projectId/shared/RecentDeploymentsCard'
import { OVERVIEW_FIREWALL_RULES_LIMIT } from '@/components/pages/projects/$projectId/shared/ResourceFirewallCard'
import { SpecificationType } from '@/lib/specifications'
import {
  getActiveProfileFeatures,
  isCloudProfile,
} from '@/lib/console-profiles'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'

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
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, functionId } = params
    const { queryClient } = context
    const usageDateRange = getStableUsageChartDateRange()

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const func = await queryClient.ensureQueryData(
      projectFunctionQueryOptions(projectId, functionId),
    )

    await Promise.all([
      func.deploymentId
        ? queryClient.ensureQueryData(
            functionDeploymentQueryOptions(
              projectId,
              functionId,
              func.deploymentId,
            ),
          )
        : Promise.resolve(),
      queryClient.ensureQueryData(
        functionDeploymentsQueryOptions(
          projectId,
          functionId,
          0,
          OVERVIEW_DEPLOYMENTS_LIMIT,
        ),
      ),
      queryClient.ensureQueryData(
        functionSpecificationsQueryOptions(
          projectId,
          SpecificationType.Runtimes,
        ),
      ),
      isCloudProfile()
        ? queryClient.ensureQueryData(
            firewallRulesQueryOptions(
              projectId,
              0,
              OVERVIEW_FIREWALL_RULES_LIMIT,
              undefined,
              'functions',
              functionId,
            ),
          )
        : Promise.resolve(),
      isCloudProfile()
        ? queryClient.ensureQueryData(
            firewallTrafficOverviewQueryOptions(
              projectId,
              undefined,
              DEFAULT_USAGE_CHART_INTERVAL,
              undefined,
              'functions',
              functionId,
              '24h',
            ),
          )
        : Promise.resolve(),
      ...(getActiveProfileFeatures().usageStats
        ? [
            queryClient.ensureQueryData(
              requestsForResourceChartQueryOptions(
                projectId,
                functionId,
                'function',
                usageDateRange,
              ),
            ),
            queryClient.ensureQueryData(
              bandwidthForResourceChartQueryOptions(
                projectId,
                functionId,
                'function',
                usageDateRange,
              ),
            ),
            queryClient.ensureQueryData(
              functionExecutionsForFunctionChartQueryOptions(
                projectId,
                functionId,
                usageDateRange,
              ),
            ),
            queryClient.ensureQueryData(
              functionGbHoursForFunctionChartQueryOptions(
                projectId,
                functionId,
                usageDateRange,
              ),
            ),
          ]
        : []),
    ])

    return { function: func }
  },
  component: View,
})
