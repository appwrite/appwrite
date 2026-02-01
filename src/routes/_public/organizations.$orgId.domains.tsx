import { createFileRoute, Outlet } from '@tanstack/react-router'

export const Route = createFileRoute('/_public/organizations/$orgId/domains')({
  component: DomainsLayout,
})

function DomainsLayout() {
  return <Outlet />
}
