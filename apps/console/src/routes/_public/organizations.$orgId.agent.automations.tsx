import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/agent/automations',
)({
  component: () => <Outlet />,
})
