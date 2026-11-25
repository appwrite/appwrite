import { createFileRoute } from '@tanstack/react-router'
import { MonitorConditionSettings } from '@/components/pages/projects/$projectId/monitors/$monitorId/settings/Condition'

export const Route = createFileRoute(
  '/_public/projects/$projectId/monitors_/$monitorId/settings/condition',
)({
  component: Page,
})

function Page() {
  const { projectId, monitorId } = Route.useParams()
  return (
    <MonitorConditionSettings projectId={projectId} monitorId={monitorId} />
  )
}
