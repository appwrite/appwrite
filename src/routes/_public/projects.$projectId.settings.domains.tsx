import { createFileRoute } from '@tanstack/react-router'
import { ProjectSettingsView } from '@/components/pages/projects/$projectId/settings/View'

export const Route = createFileRoute('/_public/projects/$projectId/settings/domains')({
  component: SettingsDomainsPage,
})

function SettingsDomainsPage() {
  return <ProjectSettingsView />
}
