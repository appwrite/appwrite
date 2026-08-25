import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/$siteId/usage/View'
import {
  organizationPlanQueryOptions,
  projectQueryOptions,
  siteExecutionsForSiteQueryOptions,
  siteGbHoursForSiteQueryOptions,
  siteQueryOptions,
} from '@/lib/react-query/hooks'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { resolveUsageChartFiltersFromPrefs } from '@/lib/usage/usage-chart-filters'
import type { UserPrefs } from '@/lib/user-prefs-keys'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/usage',
)({
  beforeLoad: ({ params }) => {
    // The project parent resolves self-hosted backend capability on the client.
    // SSR must not redirect before that Console variable is available.
    if (
      typeof window !== 'undefined' &&
      !getActiveProfileFeatures().usageStats
    ) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, siteId } = params
    const { queryClient } = context

    const [project, account] = await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(consoleAccountQueryOptions()),
      queryClient.ensureQueryData(siteQueryOptions(projectId, siteId)),
    ])
    const plan = project.teamId
      ? await queryClient
          .ensureQueryData(organizationPlanQueryOptions(project.teamId))
          .catch(() => null)
      : null
    const { dateRange, chartInterval } = resolveUsageChartFiltersFromPrefs(
      account.prefs as UserPrefs | undefined,
      plan,
    )

    await Promise.all([
      queryClient.ensureQueryData({
        ...siteExecutionsForSiteQueryOptions(
          projectId,
          siteId,
          dateRange,
          chartInterval,
        ),
        revalidateIfStale: true,
      }),
      queryClient.ensureQueryData({
        ...siteGbHoursForSiteQueryOptions(
          projectId,
          siteId,
          dateRange,
          chartInterval,
        ),
        revalidateIfStale: true,
      }),
    ])
  },
  component: SiteUsagePage,
})

function SiteUsagePage() {
  return <View />
}
