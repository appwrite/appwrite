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
  storageOverviewQueryOptions,
} from '@/lib/react-query/hooks'
import { ensureProjectRegion } from '@/lib/project-region'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import {
  getDefaultUsageChartDateRange,
  serializeUsageChartDateRange,
} from '@/lib/usage/usage-date-range'

export const Route = createFileRoute('/_public/projects/$projectId/')({
  head: () => ({ meta: [{ title: pageTitle('Overview') }] }),
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

      const chartDateRange = serializeUsageChartDateRange(
        getDefaultUsageChartDateRange(),
      )
      const usageStatsEnabled = getActiveProfileFeatures().usageStats

      // Non-critical data: prefetch in the background so the page can render immediately.
      void queryClient
        .prefetchQuery(platformsQueryOptions(projectId))
        .catch(() => undefined)

      if (usageStatsEnabled) {
        const parsedRange = {
          from: new Date(chartDateRange.from),
          to: new Date(chartDateRange.to),
        }

        // Usage is non-critical: prefetch in background; page renders with chart skeletons.
        void Promise.all([
          queryClient.prefetchQuery(
            bandwidthOverviewQueryOptions(
              projectId,
              parsedRange,
              DEFAULT_USAGE_CHART_INTERVAL,
            ),
          ),
          queryClient.prefetchQuery(
            requestsOverviewQueryOptions(
              projectId,
              parsedRange,
              DEFAULT_USAGE_CHART_INTERVAL,
            ),
          ),
          queryClient.prefetchQuery(
            executionsOverviewQueryOptions(
              projectId,
              parsedRange,
              DEFAULT_USAGE_CHART_INTERVAL,
            ),
          ),
          queryClient.prefetchQuery(
            gbHoursOverviewQueryOptions(
              projectId,
              parsedRange,
              DEFAULT_USAGE_CHART_INTERVAL,
            ),
          ),
          queryClient.prefetchQuery(
            storageOverviewQueryOptions(
              projectId,
              parsedRange,
              DEFAULT_USAGE_CHART_INTERVAL,
            ),
          ),
        ]).catch(() => undefined)
      }

      return {
        apiKeys: mapApiKeysFromResponse(apiKeysRaw),
        apiKeysRaw,
        chartDateRange,
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
              chartDateRange: loaderData.chartDateRange,
            }
          : undefined
      }
    />
  )
}
