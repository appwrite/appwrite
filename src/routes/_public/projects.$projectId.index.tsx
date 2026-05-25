import { createFileRoute } from '@tanstack/react-router'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import { View } from '@/components/pages/projects/$projectId/overview/Overview'
import {
  apiKeysQueryOptions,
  mapApiKeysFromResponse,
  platformsQueryOptions,
  bandwidthChartOverviewQueryOptions,
  bandwidthTopConsumersQueryOptions,
  requestsChartOverviewQueryOptions,
} from '@/lib/react-query/hooks'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'

function getDefaultDashboardChartRange() {
  return {
    from: startOfDay(subDays(new Date(), 29)),
    to: endOfDay(new Date()),
  }
}

export const Route = createFileRoute('/_public/projects/$projectId/')({
  head: () => ({ meta: [{ title: pageTitle('Overview') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    try {
      // Only block on API keys — project is prefetched by the parent layout.
      const apiKeysRaw = await queryClient
        .ensureQueryData(apiKeysQueryOptions(projectId))
        .catch(() => null)

      const defaultChartRange = getDefaultDashboardChartRange()
      const usageStatsEnabled = getActiveProfileFeatures().usageStats

      // Non-critical data: prefetch in the background so the page can render immediately.
      void queryClient
        .prefetchQuery(platformsQueryOptions(projectId))
        .catch(() => undefined)

      if (usageStatsEnabled) {
        void queryClient
          .prefetchQuery(
            bandwidthChartOverviewQueryOptions(projectId, defaultChartRange),
          )
          .catch(() => undefined)
        void queryClient
          .prefetchQuery(
            requestsChartOverviewQueryOptions(projectId, defaultChartRange),
          )
          .catch(() => undefined)
        void queryClient
          .prefetchQuery(
            bandwidthTopConsumersQueryOptions(projectId, defaultChartRange, true),
          )
          .catch(() => undefined)
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
