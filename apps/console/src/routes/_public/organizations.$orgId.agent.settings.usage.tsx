import { createFileRoute } from '@tanstack/react-router'
import { AgentPanelContent } from '@/components/global/providers/AgentChat'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/settings/usage',
)({
  component: SettingsUsagePage,
})

function SettingsUsagePage() {
  return (
    <AgentPanelContent
      variant="page"
      section="settings"
      settingsSection="usage"
    />
  )
}
