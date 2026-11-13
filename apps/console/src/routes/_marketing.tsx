import { createFileRoute, Outlet } from '@tanstack/react-router'
import { marketingRouteLifetime } from '@/lib/marketing/route-static-data'
import { WEBMCP_DETECT_SCRIPT } from '@/lib/webmcp/site/detect'
import { useSiteTools } from '@/lib/webmcp/site/use-site-tools'

export const Route = createFileRoute('/_marketing')({
  ...marketingRouteLifetime,
  head: () => ({ scripts: [WEBMCP_DETECT_SCRIPT] }),
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  useSiteTools()
  return <Outlet />
}
