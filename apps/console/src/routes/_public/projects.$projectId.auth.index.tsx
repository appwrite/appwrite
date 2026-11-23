import { createFileRoute } from '@tanstack/react-router'
import {
  projectQueryOptions,
  teamsQueryOptions,
  usersQueryOptions,
  authMauChartQueryOptions,
  authSignupsChartQueryOptions,
} from '@/lib/react-query/hooks'
import { projectOAuth2ProvidersQueryOptions } from '@/lib/react-query/hooks/oauth2-providers'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { projectAuthSecurityQueryOptions } from '@/lib/project-settings'
import { canAccessAuthSecuritySettings } from '@/lib/console-rbac-loader'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/auth/')({
  head: () => ({ meta: [{ title: pageTitle('Overview', 'Auth') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const canSeeSettings = await canAccessAuthSecuritySettings(
      queryClient,
      projectId,
    )

    // Same pages the Users and Teams tabs open on, so the overview and those
    // tabs share one request each.
    await Promise.all([
      queryClient.ensureQueryData(
        usersQueryOptions(projectId, 0, GRID_DEFAULT_PAGE_SIZE),
      ),
      queryClient.ensureQueryData(
        teamsQueryOptions(projectId, 0, GRID_DEFAULT_PAGE_SIZE),
      ),
      canSeeSettings
        ? Promise.all([
            queryClient.ensureQueryData(
              projectAuthSecurityQueryOptions(projectId, project?.region),
            ),
            // The sign-in methods card still renders without providers.
            queryClient
              .ensureQueryData(projectOAuth2ProvidersQueryOptions(projectId))
              .catch(() => undefined),
          ])
        : Promise.resolve(),
    ])

    // Usage fills in after navigation, so a slow usage API never delays the
    // overview.
    if (getActiveProfileFeatures().usageStats) {
      const usageDateRange = getStableUsageChartDateRange()
      void queryClient.prefetchQuery(
        authMauChartQueryOptions(
          projectId,
          usageDateRange,
          undefined,
          undefined,
          DEFAULT_USAGE_LOG_RETENTION_HOURS,
        ),
      )
      void queryClient.prefetchQuery(
        authSignupsChartQueryOptions(
          projectId,
          usageDateRange,
          undefined,
          undefined,
          DEFAULT_USAGE_LOG_RETENTION_HOURS,
        ),
      )
    }
  },
})
