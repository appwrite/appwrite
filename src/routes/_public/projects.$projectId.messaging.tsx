import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/projects/$projectId/messaging')({
  component: MessagingLayout,
})

function MessagingLayout() {
  return <Outlet />
}
