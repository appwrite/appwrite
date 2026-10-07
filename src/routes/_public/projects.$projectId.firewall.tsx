import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { isCloudProfile } from '@/lib/console-profiles'
import {
  attackModeRuleQueryOptions,
  firewallRulesQueryOptions,
  firewallTrafficOverviewQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { resolveFirewallListSearch } from '@/lib/firewall/conditions'
import { isAttackModeScope } from '@/lib/firewall/attack-mode'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { getUsageLogRetentionHoursFromPlan } from '@/lib/usage/usage-log-retention'

export const Route = createFileRoute('/_public/projects/$projectId/firewall')({
  head: () => ({ meta: [{ title: pageTitle('Firewall') }] }),
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return
    const search =
      location.search && typeof location.search === 'object'
        ? (location.search as Record<string, unknown>)
        : {}
    const selection = resolveFirewallListSearch(search)

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
      queryClient.ensureQueryData(
        firewallRulesQueryOptions(
          projectId,
          0,
          DEFAULT_PAGE_SIZE,
          undefined,
          selection.resourceType,
          selection.resourceId,
        ),
      ),
      // Unfiltered total for plan limit checks.
      queryClient.ensureQueryData(
        firewallRulesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, undefined),
      ),
      ...(isAttackModeScope(selection.resourceType, selection.resourceId)
        ? [
            queryClient.ensureQueryData(
              attackModeRuleQueryOptions(
                projectId,
                selection.resourceType,
                selection.resourceId,
              ),
            ),
          ]
        : []),
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
          selection.resourceType,
          selection.resourceId,
        ),
      )
      .catch(() => undefined)
  },
  component: FirewallLayout,
})

function FirewallLayout() {
  return <Outlet />
}
