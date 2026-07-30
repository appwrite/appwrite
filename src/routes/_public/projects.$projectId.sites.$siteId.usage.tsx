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

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    // Fetch site - blocks navigation until ready
    await queryClient.ensureQueryData(siteQueryOptions(projectId, siteId))

    // Usage is non-critical: prefetch in background so a slow usage API does not
    // block the page shell.
    void queryClient
      .prefetchQuery(siteUsageQueryOptions(projectId, siteId, 'ThirtyDays'))
      .catch(() => undefined)
  },
  component: SiteUsagePage,
})

function SiteUsagePage() {
  return <View />
}
