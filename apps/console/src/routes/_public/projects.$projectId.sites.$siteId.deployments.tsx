import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/projects/$projectId/sites/$siteId/deployments',
)({
  component: DeploymentsLayoutPage,
})

function DeploymentsLayoutPage() {
  return <Outlet />
}
