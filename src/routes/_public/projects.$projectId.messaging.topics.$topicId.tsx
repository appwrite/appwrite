import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId',
)({
  component: TopicLayout,
})

function TopicLayout() {
  return <Outlet />
}
