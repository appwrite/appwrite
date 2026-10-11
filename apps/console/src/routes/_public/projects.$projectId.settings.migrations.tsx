import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/settings/migrations',
)({
  component: SettingsMigrationsLayout,
})

function SettingsMigrationsLayout() {
  return <Outlet />
}
