import { createFileRoute } from '@tanstack/react-router'
import { SiteUsageView } from '@/components/pages/projects/$projectId/sites/SiteUsage'
import {
  siteQueryOptions,
  fetchProject,
} from '@/lib/react-query/hooks'
import { siteUsageQueryOptions } from '@/lib/react-query/hooks/sites'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/usage',
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
    await queryClient.ensureQueryData(
      siteUsageQueryOptions(projectId, siteId, 'ThirtyDays'),
    )
  },
  component: SiteUsagePage,
})

function SiteUsagePage() {
  return <SiteUsageView />
}
