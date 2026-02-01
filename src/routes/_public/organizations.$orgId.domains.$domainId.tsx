import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId',
)({
  component: DomainDetailLayout,
})

function DomainDetailLayout() {
  return <Outlet />
}
