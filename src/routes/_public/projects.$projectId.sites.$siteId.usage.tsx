import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/Usage'
import {
  siteQueryOptions,
  siteUsageQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/usage',
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
    await queryClient.ensureQueryData(
      siteUsageQueryOptions(projectId, siteId, 'ThirtyDays'),
    )
  },
  component: SiteUsagePage,
})

function SiteUsagePage() {
  return <View />
}
