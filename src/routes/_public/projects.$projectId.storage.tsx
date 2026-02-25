import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/storage')({
  head: () => ({ meta: [{ title: pageTitle('Storage') }] }),
  component: StorageLayout,
})

function StorageLayout() {
  return <Outlet />
}
