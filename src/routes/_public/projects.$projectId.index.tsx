import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/overview/Overview'
import {
  apiKeysQueryOptions,
  mapApiKeysFromResponse,
  platformsQueryOptions,
  bandwidthOverviewQueryOptions,
  requestsOverviewQueryOptions,
  executionsOverviewQueryOptions,
  gbHoursOverviewQueryOptions,
  overviewStorageOverviewQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { ensureProjectRegion } from '@/lib/project-region'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { loadDebugOverrides } from '@/lib/debug-overrides'
import {
  isOverviewChartTabEnabled,
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

export const Route = createFileRoute('/_public/projects/$projectId/')({
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

      // API keys can hang when billable services are blocked. Bound the wait so the
      // project layout (and budget curtain) can still mount.
      const apiKeysRaw = await Promise.race([
        queryClient
          .ensureQueryData(apiKeysQueryOptions(projectId))
          .catch(() => null),
        new Promise<null>((resolve) => {
          window.setTimeout(() => resolve(null), 4000)
        }),
      ])

      const account = await queryClient
        .ensureQueryData(consoleAccountQueryOptions())
        .catch(() => null)
      const usageStatsEnabled = getActiveProfileFeatures().usageStats

      // Non-critical data: prefetch in the background so the page can render immediately.
      void queryClient
        .prefetchQuery(platformsQueryOptions(projectId))
        .catch(() => undefined)

      if (usageStatsEnabled) {
        const project = await queryClient
          .ensureQueryData({
            queryKey: ['project', projectId],
            queryFn: () => fetchProject(projectId),
            staleTime: 5 * 60 * 1000,
          })
          .catch(() => null)

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
        const debugOverrides = loadDebugOverrides()

        // Usage is non-critical: prefetch in background; page renders with chart skeletons.
        const usagePrefetchTasks = OVERVIEW_CHART_TAB_ORDER.filter((tabId) =>
          isOverviewChartTabEnabled(tabId, debugOverrides),
        ).map((tabId) =>
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

      return {
        apiKeys: mapApiKeysFromResponse(apiKeysRaw),
        apiKeysRaw,
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
  const loaderData = Route.useLoaderData()
  return (
    <View
      projectId={projectId}
      initialData={
        loaderData
          ? {
              apiKeys: loaderData.apiKeys,
              apiKeysRaw: loaderData.apiKeysRaw,
            }
          : undefined
      }
    />
  )
}
