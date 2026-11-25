import { createFileRoute } from '@tanstack/react-router'
import { MonitorNotificationSettings } from '@/components/pages/projects/$projectId/monitors/$monitorId/settings/Notifications'

export const Route = createFileRoute(
  '/_public/projects/$projectId/monitors_/$monitorId/settings/notifications',
)({
  component: Page,
})

function Page() {
  const { projectId, monitorId } = Route.useParams()
  return (
    <MonitorNotificationSettings projectId={projectId} monitorId={monitorId} />
  )
}
