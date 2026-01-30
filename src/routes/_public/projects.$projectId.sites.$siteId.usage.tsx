import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Usage'
import {
  siteQueryOptions,
  siteUsageQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

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
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await queryClient.ensureQueryData(
      siteUsageQueryOptions(projectId, siteId, 'ThirtyDays'),
    )
  },
  component: SiteUsagePage,
})

function SiteUsagePage() {
  return <View />
}
