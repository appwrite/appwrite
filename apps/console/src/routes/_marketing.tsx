import { createFileRoute, Outlet } from '@tanstack/react-router'
import { marketingRouteLifetime } from '@/lib/marketing/route-static-data'
import { redirectIfMarketingDisabled } from '@/lib/marketing/marketing-route-guard'

export const Route = createFileRoute('/_marketing')({
  ...marketingRouteLifetime,
  beforeLoad: ({ location }) => redirectIfMarketingDisabled(location.pathname),
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  return <Outlet />
}
