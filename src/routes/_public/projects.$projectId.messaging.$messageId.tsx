import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/$messageId',
)({
  component: MessageLayout,
})

function MessageLayout() {
  return <Outlet />
}
