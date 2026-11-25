import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/overview/Overview'
import {
  apiKeysQueryOptions,
  platformsQueryOptions,
  bandwidthOverviewQueryOptions,
  requestsOverviewQueryOptions,
  executionsOverviewQueryOptions,
  gbHoursOverviewQueryOptions,
  overviewStorageOverviewQueryOptions,
  projectQueryOptions,
  organizationPlanQueryOptions,
  accountConsentsQueryOptions,
  overviewDatabasesQueryOptions,
  overviewSitesQueryOptions,
  overviewFunctionsQueryOptions,
  overviewBucketsQueryOptions,
  overviewUsersQueryOptions,
  overviewTopicsQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_STALE_TIME } from '@/lib/react-query/hooks/constants'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import { hasAccountMcpAgentConnected } from '@/lib/mcp-adoption'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { ensureProjectRegion } from '@/lib/project-region'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  OVERVIEW_CHART_TAB_ORDER,
  type OverviewChartTabId,
} from '@/lib/overview-chart-tabs'
import { pageTitle } from '@/lib/utils/page-title'
import { resolveUsageChartFiltersFromPrefs } from '@/lib/usage/usage-chart-filters'
import { getUsageLogRetentionHoursFromPlan } from '@/lib/usage/usage-log-retention'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import type { UserPrefs } from '@/lib/user-prefs-keys'

const OVERVIEW_CHART_PREFETCH_BY_TAB: Record<
  OverviewChartTabId,
  (
    projectId: string,
    parsedRange: { from: Date; to: Date },
    chartInterval: UsageChartInterval,
    includeBreakdown: boolean,
    logRetentionHours: number,
  ) => ReturnType<
    | typeof bandwidthOverviewQueryOptions
    | typeof requestsOverviewQueryOptions
    | typeof executionsOverviewQueryOptions
    | typeof gbHoursOverviewQueryOptions
    | typeof overviewStorageOverviewQueryOptions
  >
> = {
  bandwidth: (projectId, parsedRange, chartInterval, includeBreakdown, logRetentionHours) =>
    bandwidthOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
      undefined,
      logRetentionHours,
    ),
  requests: (projectId, parsedRange, chartInterval, includeBreakdown, logRetentionHours) =>
    requestsOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
      undefined,
      logRetentionHours,
    ),
  executions: (projectId, parsedRange, chartInterval, includeBreakdown, logRetentionHours) =>
    executionsOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
      undefined,
      logRetentionHours,
    ),
  gbhours: (projectId, parsedRange, chartInterval, includeBreakdown, logRetentionHours) =>
    gbHoursOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
      undefined,
      logRetentionHours,
    ),
  storage: (projectId, parsedRange, chartInterval, includeBreakdown, logRetentionHours) =>
    overviewStorageOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
      undefined,
      logRetentionHours,
    ),
}

export const Route = createFileRoute('/_public/projects/$projectId/overview')({
  head: () => ({ meta: [{ title: pageTitle('Dashboard') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    // Budget/plan-locked orgs block billable project APIs; skip prefetch so the curtain can render.
    if (context.budgetLimitReached || context.planUsageLimitReached) {
      return undefined
    }

    try {
      // Parent layout loader may still be resolving region; register before project-scoped prefetch.
      await ensureProjectRegion(queryClient, projectId)

      // Share the in-flight key request with its card and reuse it on short return visits.
      void queryClient.prefetchQuery({
        ...apiKeysQueryOptions(projectId),
        gcTime: DEFAULT_STALE_TIME,
      })

      // Non-critical data: prefetch in the background so the page can render immediately.
      void queryClient
        .prefetchQuery(platformsQueryOptions(projectId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(overviewDatabasesQueryOptions(projectId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(overviewSitesQueryOptions(projectId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(overviewFunctionsQueryOptions(projectId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(overviewBucketsQueryOptions(projectId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(overviewUsersQueryOptions(projectId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(overviewTopicsQueryOptions(projectId))
        .catch(() => undefined)

      if (getActiveProfileFeatures().usageStats) {
        const [project, account] = await Promise.all([
          queryClient
            .ensureQueryData(projectQueryOptions(projectId))
            .catch(() => null),
          queryClient
            .ensureQueryData(consoleAccountQueryOptions())
            .catch(() => null),
        ])
        const organizationPlan = project?.teamId
          ? await queryClient
              .ensureQueryData(organizationPlanQueryOptions(project.teamId))
              .catch(() => null)
          : null
        const logRetentionHours = getUsageLogRetentionHoursFromPlan(
          organizationPlan,
        )
        const usageChartFilters = resolveUsageChartFiltersFromPrefs(
          account?.prefs as UserPrefs | undefined,
          organizationPlan,
        )
        const parsedRange = {
          from: usageChartFilters.dateRange.from!,
          to: usageChartFilters.dateRange.to!,
        }
        const chartInterval = usageChartFilters.chartInterval

        // Usage is non-critical: prefetch in background; page renders with chart skeletons.
        const usagePrefetchTasks = OVERVIEW_CHART_TAB_ORDER.map((tabId) =>
          queryClient.prefetchQuery(
            OVERVIEW_CHART_PREFETCH_BY_TAB[tabId](
              projectId,
              parsedRange,
              chartInterval,
              tabId === 'bandwidth',
              logRetentionHours,
            ),
          ),
        )

        void Promise.all(usagePrefetchTasks).catch(() => undefined)
      }

      if (loadDebugOverrides().showProjectAgents) {
        const consents = await queryClient
          .ensureQueryData(accountConsentsQueryOptions())
          .catch(() => null)
        return hasAccountMcpAgentConnected(consents)
      }
    } catch (error) {
      console.warn('Failed to fetch overview data in loader:', error)
      return undefined
    }
  },
  component: ProjectOverviewPage,
})

function ProjectOverviewPage() {
  const { projectId } = Route.useParams()
  const mcpAgentConnected = Route.useLoaderData()
  return <View projectId={projectId} mcpAgentConnected={mcpAgentConnected} />
}
