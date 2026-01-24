import { createFileRoute } from '@tanstack/react-router'
import { SiteSettingsView } from '@/components/pages/projects/$projectId/sites/SiteSettings'
import {
  siteQueryOptions,
  fetchProject,
  fetchProjectVariables,
  fetchVcsInstallations,
} from '@/lib/react-query/hooks'
import {
  fetchSiteVariables,
  fetchSiteFrameworks,
  fetchSiteSpecifications,
} from '@/lib/react-query/hooks/sites'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/settings',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, siteId } = params
    const { queryClient } = context

    // Fetch site first - blocks navigation until ready
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

    // Fetch project data (needed for header/sidebar) - blocks navigation
    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch project variables
      queryClient.ensureQueryData({
        queryKey: ['variables', 'project', projectId],
        queryFn: () => fetchProjectVariables(projectId),
        staleTime: 5 * 60 * 1000,
      }),
      // Fetch site variables
      queryClient.ensureQueryData({
        queryKey: ['variables', 'site', projectId, siteId],
        queryFn: () => fetchSiteVariables(projectId, siteId),
        staleTime: 5 * 60 * 1000,
      }),
      // Fetch frameworks
      queryClient.ensureQueryData({
        queryKey: ['frameworks', 'sites', projectId],
        queryFn: () => fetchSiteFrameworks(projectId),
        staleTime: 5 * 60 * 1000,
      }),
      // Fetch VCS installations
      queryClient.ensureQueryData({
        queryKey: ['vcs', 'installations', projectId, 0, 25],
        queryFn: () => fetchVcsInstallations(projectId, 0, 25),
        staleTime: 5 * 60 * 1000,
      }),
      // Fetch specifications (cloud only)
      queryClient
        .ensureQueryData({
          queryKey: ['specifications', 'site', projectId],
          queryFn: () => fetchSiteSpecifications(projectId),
          staleTime: 5 * 60 * 1000,
        })
        .catch(() => {
          // Ignore errors - specifications might not be available in self-hosted
        }),
    ])
  },
  component: SiteSettingsPage,
})

function SiteSettingsPage() {
  return <SiteSettingsView />
}
