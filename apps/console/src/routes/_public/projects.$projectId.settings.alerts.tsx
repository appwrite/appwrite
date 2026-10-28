import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  alertRulesQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { isCloudProfile } from '@/lib/console-profiles'
import { View } from '@/components/pages/projects/$projectId/settings/View'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/alerts',
)({
  head: () => ({ meta: [{ title: pageTitle('Alerts', 'Settings') }] }),
  beforeLoad: ({ params }) => {
    if (!isCloudProfile()) {
      throw redirect({
        to: '/projects/$projectId/settings',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId } = params
    const { queryClient } = context

    // Region must be cached before the project-scoped alerts call.
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(alertRulesQueryOptions(projectId))
  },
  component: SettingsAlertsPage,
})

function SettingsAlertsPage() {
  return <View />
}
