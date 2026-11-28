import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/logs/View'
import { projectQueryOptions } from '@/lib/react-query/hooks'
import { isCloudProfile, isFeatureEnabled } from '@/lib/console-profiles'
import { runtimeLogsSearchSchema } from '@/lib/runtime-log-filters'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/logs')({
  // Filters live in the URL, so a filtered view can be shared and reloaded
  validateSearch: runtimeLogsSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Logs') }] }),
  beforeLoad: ({ params }) => {
    // Cloud only: self-hosted doesn't serve `/v1/logs`, even with the flag on.
    if (!isCloudProfile() || !isFeatureEnabled('runtimeLogs')) {
      throw redirect({
        to: '/projects/$projectId/overview',
        params,
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') return
    // Fetch project first so setProjectRegion runs and `/v1/logs` goes to the project's region
    await context.queryClient.ensureQueryData(
      projectQueryOptions(params.projectId),
    )
  },
  component: View,
})
