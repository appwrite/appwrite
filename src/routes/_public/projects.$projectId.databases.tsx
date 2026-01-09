import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/databases')({
  component: DatabasesLayout,
})

function DatabasesLayout() {
  return <Outlet />
}
