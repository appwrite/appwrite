import { createFileRoute } from '@tanstack/react-router'
import { AgentsView } from '@/components/pages/agent/AgentsView'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/$agentId',
)({
  component: AgentDetailPage,
})

function AgentDetailPage() {
  const { agentId } = Route.useParams()
  return <AgentsView agentId={agentId} />
}
