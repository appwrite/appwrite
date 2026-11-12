import { createFileRoute, Outlet } from '@tanstack/react-router'
import { marketingRouteLifetime } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing')({
  ...marketingRouteLifetime,
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  return <Outlet />
}
