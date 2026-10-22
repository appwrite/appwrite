import { AgentPanelContent } from '@/components/global/providers/AgentChat'

type AgentsViewProps = {
  agentId?: string
}

/** Thin route wrapper — same surface as the console right pane. */
export function AgentsView({ agentId }: AgentsViewProps = {}) {
  return (
    <AgentPanelContent
      variant="page"
      section="agents"
      routeAgentId={agentId}
    />
  )
}
