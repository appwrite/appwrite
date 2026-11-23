import { createFileRoute } from '@tanstack/react-router'
import { AgentPanelContent } from '@/components/global/providers/AgentChat'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/automations/',
)({
  component: AutomationsIndexPage,
})

function AutomationsIndexPage() {
  return (
    <AgentPanelContent
      variant="page"
      section="automations"
      automationMode="list"
    />
  )
}
