import { createFileRoute, Outlet } from '@tanstack/react-router'
import { marketingRouteLifetime } from '@/lib/marketing/route-static-data'
import { useSiteTools } from '@/lib/webmcp/site/use-site-tools'

export const Route = createFileRoute('/_marketing')({
  ...marketingRouteLifetime,
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  useSiteTools()
  return <Outlet />
}
