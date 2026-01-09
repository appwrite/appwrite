import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId',
)({
  component: TableLayout,
})

function TableLayout() {
  return <Outlet />
}
