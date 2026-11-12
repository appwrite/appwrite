import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/messaging')({
  head: () => ({ meta: [{ title: pageTitle('Messaging') }] }),
  component: MessagingLayout,
})

function MessagingLayout() {
  return <Outlet />
}
