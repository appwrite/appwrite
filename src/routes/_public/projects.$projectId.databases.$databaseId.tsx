import { createFileRoute, Outlet } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
  component: DatabaseLayout,
})

// Layout route that renders child routes (table views)
function DatabaseLayout() {
  return <Outlet />
}
