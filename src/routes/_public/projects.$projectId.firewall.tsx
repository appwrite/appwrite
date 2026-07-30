import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  firewallRulesQueryOptions,
  firewallTrafficOverviewQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { getUsageLogRetentionHoursFromPlan } from '@/lib/usage/usage-log-retention'

export const Route = createFileRoute('/_public/projects/$projectId/firewall')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().firewall) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const project = await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    const plan = project?.teamId
      ? await queryClient
          .ensureQueryData(organizationPlanQueryOptions(project.teamId))
          .catch(() => undefined)
      : undefined

    const dateRange = getStableUsageChartDateRange()
    const chartInterval = resolveUsageChartIntervalForRange(
      DEFAULT_USAGE_CHART_INTERVAL,
      dateRange,
      plan,
    )
    const logRetentionHours = getUsageLogRetentionHoursFromPlan(plan)

    await Promise.all([
      // Default rules tab (API) + unfiltered total for plan limit checks.
      queryClient.ensureQueryData(
        firewallRulesQueryOptions(
          projectId,
          0,
          DEFAULT_PAGE_SIZE,
          undefined,
          'api',
        ),
      ),
      queryClient.ensureQueryData(
        firewallRulesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, undefined),
      ),
    ])

    // Usage is non-critical: prefetch in background so a slow usage API does not
    // block the rules list or navigation.
    void queryClient
      .prefetchQuery(
        firewallTrafficOverviewQueryOptions(
          projectId,
          dateRange,
          chartInterval,
          logRetentionHours,
        ),
      )
      .catch(() => undefined)
  },
  component: FirewallLayout,
})

function FirewallLayout() {
  return <Outlet />
}
