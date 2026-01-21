import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId',
)({
  component: DomainDetailLayout,
})

function DomainDetailLayout() {
  // This is a layout route that just renders the outlet
  // The index route handles the detail view rendering
  return <Outlet />
}
