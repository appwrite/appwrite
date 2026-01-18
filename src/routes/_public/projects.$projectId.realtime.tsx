import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/realtime')({
  component: RealtimeLayout,
})

function RealtimeLayout() {
  return <Outlet />
}
