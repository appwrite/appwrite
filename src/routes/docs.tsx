import { createFileRoute, Outlet } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { DocsPageShell } from '@/lib/docs/DocsPageShell'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { redirectIfMarketingDisabled } from '@/lib/marketing/marketing-route-guard'

export const Route = createFileRoute('/docs')({
  ...marketingRouteLifetime,
  beforeLoad: ({ location }) => redirectIfMarketingDisabled(location.pathname),
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
