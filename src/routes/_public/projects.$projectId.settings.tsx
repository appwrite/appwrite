import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/settings')({
  head: () => ({ meta: [{ title: pageTitle('Settings') }] }),
  component: SettingsLayout,
})

function SettingsLayout() {
  return <Outlet />
}
