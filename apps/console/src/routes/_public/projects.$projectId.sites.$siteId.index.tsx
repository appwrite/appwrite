import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Overview'
import {
  siteQueryOptions,
  siteDeploymentQueryOptions,
  siteDeploymentsQueryOptions,
  siteDomainsQueryOptions,
  siteFrameworksQueryOptions,
  siteSpecificationsQueryOptions,
  firewallRulesQueryOptions,
  firewallTrafficOverviewQueryOptions,
  requestsForResourceChartQueryOptions,
  bandwidthForResourceChartQueryOptions,
  siteExecutionsForSiteChartQueryOptions,
  siteGbHoursForSiteChartQueryOptions,
  DEFAULT_USAGE_CHART_INTERVAL,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { DOMAINS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { SpecificationType } from '@/lib/specifications'
import { Query } from '@appwrite.io/console'
import { OVERVIEW_DEPLOYMENTS_LIMIT } from '@/components/pages/projects/$projectId/shared/RecentDeploymentsCard'
import { OVERVIEW_FIREWALL_RULES_LIMIT } from '@/components/pages/projects/$projectId/shared/ResourceFirewallCard'
import {
  getActiveProfileFeatures,
  isCloudProfile,
} from '@/lib/console-profiles'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context
    const usageDateRange = getStableUsageChartDateRange()

    try {
      await queryClient.ensureQueryData(projectQueryOptions(projectId))
      const site = await queryClient.ensureQueryData(
        siteQueryOptions(projectId, siteId),
      )

      await Promise.all([
        site.deploymentId
          ? queryClient.ensureQueryData(
              siteDeploymentQueryOptions(projectId, siteId, site.deploymentId),
            )
          : Promise.resolve(),
        queryClient.ensureQueryData(
          siteDeploymentsQueryOptions(
            projectId,
            siteId,
            0,
            OVERVIEW_DEPLOYMENTS_LIMIT,
            [
              Query.select([
                'status',
                'type',
                'resourceId',
                'buildSize',
                'sourceSize',
                'buildDuration',
                'providerRepositoryUrl',
                'providerRepositoryOwner',
                'providerRepositoryName',
                'providerBranchUrl',
                'providerBranch',
                'providerCommitMessage',
                'providerCommitHash',
                'providerCommitUrl',
                'providerCommitAuthor',
                'providerCommitAuthorUrl',
                '$createdAt',
              ]),
            ],
          ),
        ),
        queryClient.ensureQueryData(
          siteDomainsQueryOptions(
            projectId,
            siteId,
            0,
            DOMAINS_DEFAULT_PAGE_SIZE,
            '',
          ),
        ),
        queryClient.ensureQueryData(siteFrameworksQueryOptions(projectId)),
        queryClient.ensureQueryData(
          siteSpecificationsQueryOptions(
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
                'sites',
                siteId,
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
                'sites',
                siteId,
                '24h',
              ),
            )
          : Promise.resolve(),
        ...(getActiveProfileFeatures().usageStats
          ? [
              queryClient.ensureQueryData(
                requestsForResourceChartQueryOptions(
                  projectId,
                  siteId,
                  'site',
                  usageDateRange,
                ),
              ),
              queryClient.ensureQueryData(
                bandwidthForResourceChartQueryOptions(
                  projectId,
                  siteId,
                  'site',
                  usageDateRange,
                ),
              ),
              queryClient.ensureQueryData(
                siteExecutionsForSiteChartQueryOptions(
                  projectId,
                  siteId,
                  usageDateRange,
                ),
              ),
              queryClient.ensureQueryData(
                siteGbHoursForSiteChartQueryOptions(
                  projectId,
                  siteId,
                  usageDateRange,
                ),
              ),
            ]
          : []),
      ])
    } catch (error) {
      console.warn('Failed to fetch site overview in loader:', error)
    }
  },
  component: SiteOverviewPage,
})

function SiteOverviewPage() {
  return <View />
}
