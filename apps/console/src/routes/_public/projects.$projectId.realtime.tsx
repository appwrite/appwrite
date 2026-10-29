import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/realtime')({
  head: () => ({ meta: [{ title: pageTitle('Realtime') }] }),
  component: RealtimeLayout,
})

function RealtimeLayout() {
  return <Outlet />
}
