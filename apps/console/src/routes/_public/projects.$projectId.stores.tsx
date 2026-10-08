import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/stores')({
  component: StoresLayout,
})

function StoresLayout() {
  return <Outlet />
}
