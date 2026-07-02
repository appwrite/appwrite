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
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import type { UserPrefs } from '@/lib/user-prefs-keys'

const OVERVIEW_CHART_PREFETCH_BY_TAB: Record<
  OverviewChartTabId,
  (
    projectId: string,
    parsedRange: { from: Date; to: Date },
    chartInterval: UsageChartInterval,
    includeBreakdown: boolean,
  ) => ReturnType<
    | typeof bandwidthOverviewQueryOptions
    | typeof requestsOverviewQueryOptions
    | typeof executionsOverviewQueryOptions
    | typeof gbHoursOverviewQueryOptions
    | typeof overviewStorageOverviewQueryOptions
  >
> = {
  bandwidth: (projectId, parsedRange, chartInterval, includeBreakdown) =>
    bandwidthOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
    ),
  requests: (projectId, parsedRange, chartInterval, includeBreakdown) =>
    requestsOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
    ),
  executions: (projectId, parsedRange, chartInterval, includeBreakdown) =>
    executionsOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
    ),
  gbhours: (projectId, parsedRange, chartInterval, includeBreakdown) =>
    gbHoursOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
    ),
  storage: (projectId, parsedRange, chartInterval, includeBreakdown) =>
    overviewStorageOverviewQueryOptions(
      projectId,
      parsedRange,
      chartInterval,
      includeBreakdown,
    ),
}

export const Route = createFileRoute('/_public/projects/$projectId/')({
  head: () => ({ meta: [{ title: pageTitle('Dashboard') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    try {
      // Parent layout loader may still be resolving region; register before project-scoped prefetch.
      await ensureProjectRegion(queryClient, projectId)

      // Only block on API keys — project is prefetched by the parent layout.
      const apiKeysRaw = await queryClient
        .ensureQueryData(apiKeysQueryOptions(projectId))
        .catch(() => null)

      const account = await queryClient
        .ensureQueryData(consoleAccountQueryOptions())
        .catch(() => null)
      const usageChartFilters = resolveUsageChartFiltersFromPrefs(
        account?.prefs as UserPrefs | undefined,
      )
      const usageStatsEnabled = getActiveProfileFeatures().usageStats

      // Non-critical data: prefetch in the background so the page can render immediately.
      void queryClient
        .prefetchQuery(platformsQueryOptions(projectId))
        .catch(() => undefined)

      if (usageStatsEnabled) {
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
