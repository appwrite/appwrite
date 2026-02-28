import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/domains',
)({
  head: () => ({ meta: [{ title: pageTitle('Domains', 'Settings') }] }),
  component: SettingsDomainsLayout,
})

function SettingsDomainsLayout() {
  return <Outlet />
}
