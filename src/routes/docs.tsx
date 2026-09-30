import { createFileRoute, Outlet } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { DocsPageShell } from '@/lib/docs/DocsPageShell'

export const Route = createFileRoute('/docs')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  notFoundComponent: NotFoundView,
  component: DocsLayoutRoute,
})

function DocsLayoutRoute() {
  return (
    <DocsPageShell>
      <Outlet />
    </DocsPageShell>
  )
}
