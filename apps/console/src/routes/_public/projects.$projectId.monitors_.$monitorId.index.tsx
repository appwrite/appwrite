import { createFileRoute } from '@tanstack/react-router'
import { MonitorOverview } from '@/components/pages/projects/$projectId/monitors/$monitorId/Overview'

export const Route = createFileRoute(
  '/_public/projects/$projectId/monitors_/$monitorId/',
)({
  component: MonitorOverviewPage,
})

function MonitorOverviewPage() {
  const { projectId, monitorId } = Route.useParams()
  return <MonitorOverview projectId={projectId} monitorId={monitorId} />
}
