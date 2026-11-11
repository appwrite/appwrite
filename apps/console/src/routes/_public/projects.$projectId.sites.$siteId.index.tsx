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
import { ensureQueryDataIfFound } from '@/lib/react-query/ensure-query-data-if-found'
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
        // The site can still point at a deleted deployment.
        site.deploymentId
          ? ensureQueryDataIfFound(
              queryClient,
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
          siteSpecificationsQueryOptions(projectId, SpecificationType.Runtimes),
        ),
      ])

      // Firewall and usage cards fill in after navigation, so a slow or
      // failing usage API neither delays nor breaks the overview.
      if (isCloudProfile()) {
        void queryClient.prefetchQuery(
          firewallRulesQueryOptions(
            projectId,
            0,
            OVERVIEW_FIREWALL_RULES_LIMIT,
            undefined,
            'sites',
            siteId,
          ),
        )
        void queryClient.prefetchQuery(
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
      }
      if (getActiveProfileFeatures().usageStats) {
        void queryClient.prefetchQuery(
          requestsForResourceChartQueryOptions(
            projectId,
            siteId,
            'site',
            usageDateRange,
          ),
        )
        void queryClient.prefetchQuery(
          bandwidthForResourceChartQueryOptions(
            projectId,
            siteId,
            'site',
            usageDateRange,
          ),
        )
        void queryClient.prefetchQuery(
          siteExecutionsForSiteChartQueryOptions(
            projectId,
            siteId,
            usageDateRange,
          ),
        )
        void queryClient.prefetchQuery(
          siteGbHoursForSiteChartQueryOptions(
            projectId,
            siteId,
            usageDateRange,
          ),
        )
      }
    } catch (error) {
      console.warn('Failed to fetch site overview in loader:', error)
    }
  },
  component: SiteOverviewPage,
})

function SiteOverviewPage() {
  return <View />
}
