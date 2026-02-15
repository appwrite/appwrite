import { createFileRoute } from '@tanstack/react-router'
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
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
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
        vcsInstallationsQueryOptions(projectId, 0, 25),
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
