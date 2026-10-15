import { createFileRoute } from '@tanstack/react-router'
import { AgentPanelContent } from '@/components/global/providers/AgentChat'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/automations/$automationId',
)({
  component: AutomationsDetailPage,
})

function AutomationsDetailPage() {
  const { automationId } = Route.useParams()
  return (
    <AgentPanelContent
      variant="page"
      section="automations"
      automationMode="detail"
      routeAutomationId={automationId}
    />
  )
}
