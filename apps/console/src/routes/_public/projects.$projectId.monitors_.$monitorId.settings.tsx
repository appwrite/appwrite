import { createFileRoute, redirect } from '@tanstack/react-router'
import { MonitorSettingsLayout } from '@/components/pages/projects/$projectId/monitors/$monitorId/settings/Layout'
import { canAccessMonitorSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/monitors_/$monitorId/settings',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    // Members who cannot change monitors see the monitor, not its settings.
    if (
      !(await canAccessMonitorSettings(context.queryClient, params.projectId))
    ) {
      throw redirect({
        to: '/projects/$projectId/monitors/$monitorId',
        params,
        replace: true,
      })
    }
  },
  component: MonitorSettingsPage,
})

function MonitorSettingsPage() {
  const { projectId, monitorId } = Route.useParams()
  return <MonitorSettingsLayout projectId={projectId} monitorId={monitorId} />
}
