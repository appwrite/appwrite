import { createFileRoute } from '@tanstack/react-router'
import { MonitorGeneralSettings } from '@/components/pages/projects/$projectId/monitors/$monitorId/settings/General'

export const Route = createFileRoute(
  '/_public/projects/$projectId/monitors_/$monitorId/settings/',
)({
  component: Page,
})

function Page() {
  const { projectId, monitorId } = Route.useParams()
  return <MonitorGeneralSettings projectId={projectId} monitorId={monitorId} />
}
