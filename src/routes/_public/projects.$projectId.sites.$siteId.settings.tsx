import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Settings'
import {
  siteQueryOptions,
  siteVariablesQueryOptions,
  siteFrameworksQueryOptions,
  siteSpecificationsQueryOptions,
  projectQueryOptions,
  projectVariablesQueryOptions,
  vcsInstallationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessSiteSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.site?.name ?? loaderData?.site?.resourceId ?? 'Site',
          'Sites',
        ),
      },
    ],
  }),
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
    // Fetch site - blocks navigation until ready
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

    // Fetch critical data before rendering to prevent layout shifts
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await Promise.all([
      // Fetch project variables
      queryClient.ensureQueryData(projectVariablesQueryOptions(projectId)),
      // Fetch site variables
      queryClient.ensureQueryData(siteVariablesQueryOptions(projectId, siteId)),
      // Fetch frameworks
      queryClient.ensureQueryData(siteFrameworksQueryOptions(projectId)),
      // Fetch VCS installations
      queryClient.ensureQueryData(
        vcsInstallationsQueryOptions(projectId, 0, 10),
      ),
      // Fetch specifications (cloud only)
      queryClient
        .ensureQueryData(siteSpecificationsQueryOptions(projectId))
        .catch(() => {
          // Ignore errors - specifications might not be available in self-hosted
        }),
    ])
  },
  component: SiteSettingsPage,
})

function SiteSettingsPage() {
  return <View />
}
