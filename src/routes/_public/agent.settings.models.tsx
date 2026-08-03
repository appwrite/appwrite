import { createFileRoute } from '@tanstack/react-router'
import { AgentPanelContent } from '@/components/global/providers/AgentChat'

export const Route = createFileRoute('/_public/agent/settings/models')({
  component: SettingsModelsPage,
})

function SettingsModelsPage() {
  return (
    <AgentPanelContent
      variant="page"
      section="settings"
      settingsSection="models"
    />
  )
}
