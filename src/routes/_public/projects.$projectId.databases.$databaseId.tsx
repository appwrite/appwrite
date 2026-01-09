import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId',
)({
  component: DatabaseLayout,
})

// Layout route that renders child routes (table views)
function DatabaseLayout() {
  return <Outlet />
}
