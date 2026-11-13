import { createFileRoute, Outlet, ScriptOnce } from '@tanstack/react-router'
import { marketingRouteLifetime } from '@/lib/marketing/route-static-data'
import { SITE_TOOLS_SCRIPT } from '@/lib/webmcp/site/tools'
import {
  siteToolsEnabled,
  useSiteTools,
} from '@/lib/webmcp/site/use-site-tools'

export const Route = createFileRoute('/_marketing')({
  ...marketingRouteLifetime,
  component: MarketingLayoutRoute,
})

function MarketingLayoutRoute() {
  useSiteTools()
  return (
    <>
      {siteToolsEnabled() && <ScriptOnce>{SITE_TOOLS_SCRIPT}</ScriptOnce>}
      <Outlet />
    </>
  )
}
