import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/storage')({
  component: StorageLayout,
})

function StorageLayout() {
  return <Outlet />
}
