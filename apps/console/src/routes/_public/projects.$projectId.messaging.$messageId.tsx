import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/$messageId',
)({
  head: () => ({ meta: [{ title: pageTitle('Message', 'Messaging') }] }),
  component: MessageLayout,
})

function MessageLayout() {
  return <Outlet />
}
