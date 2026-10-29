import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/databases')({
  head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
  component: DatabasesLayout,
})

function DatabasesLayout() {
  return <Outlet />
}
