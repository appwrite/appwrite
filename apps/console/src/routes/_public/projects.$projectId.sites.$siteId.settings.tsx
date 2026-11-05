import { createFileRoute, redirect } from '@tanstack/react-router'
import { SiteSettingsLayout } from '@/components/pages/projects/$projectId/sites/settings/SiteSettingsLayout'
import {
  siteQueryOptions,
  siteVariablesQueryOptions,
  siteFrameworksQueryOptions,
  siteSpecificationsQueryOptions,
  projectQueryOptions,
  projectVariablesQueryOptions,
  vcsInstallationsQueryOptions,
} from '@/lib/react-query/hooks'
import { SpecificationType } from '@/lib/specifications'
import { canAccessSiteSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/settings',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, siteId } = params
    const { queryClient } = context

    const canAccess = await canAccessSiteSettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId, siteId },
        replace: true,
      })
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

    await Promise.all([
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      queryClient.ensureQueryData(siteVariablesQueryOptions(projectId, siteId)),
      queryClient.ensureQueryData(siteFrameworksQueryOptions(projectId)),
      queryClient.ensureQueryData(
        vcsInstallationsQueryOptions(projectId, 0, 10),
      ),
      queryClient
        .ensureQueryData(
          siteSpecificationsQueryOptions(
            projectId,
            SpecificationType.Runtimes,
          ),
        )
        .catch(() => {}),
      queryClient
        .ensureQueryData(
          siteSpecificationsQueryOptions(projectId, SpecificationType.Builds),
        )
        .catch(() => {}),
    ])
  },
  component: SiteSettingsLayout,
})
