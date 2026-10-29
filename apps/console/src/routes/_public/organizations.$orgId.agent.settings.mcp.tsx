import { createFileRoute } from '@tanstack/react-router'
import { AgentPanelContent } from '@/components/global/providers/AgentChat'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/settings/mcp',
)({
  component: SettingsMcpPage,
})

function SettingsMcpPage() {
  return (
    <AgentPanelContent
      variant="page"
      section="settings"
      settingsSection="mcp"
    />
  )
}
