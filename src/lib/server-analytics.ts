/**
 * Server-side Plausible pageview tracking for non-HTML endpoints (markdown
 * exports, llms.txt) that are fetched by LLMs, crawlers, and scripts which
 * never execute the client analytics script.
 *
 * Mirrors the first-party proxy behavior: events are sent to the upstream
 * Plausible /api/event endpoint with the visitor IP and user agent forwarded.
 * These are all public pages, so the full concrete path of the requested
 * page is reported (e.g. /blog/post/my-post.md), matching client-side
 * tracking for public pages.
 */
import { getAnalyticsArea, getAnalyticsSurface } from '@/lib/analytics'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import {
  getClientIpFromRequest,
  resolvePlausibleEventUrl,
} from '@/lib/plausible-proxy'
import { getRuntimeConfig } from '@/lib/runtime-config'

/** Fire-and-forget: analytics must never delay or break the response. */
export function trackServerPageview(request: Request): void {
  if (process.env.TSS_PRERENDERING === 'true') return

  const scriptSrc = getRuntimeConfig().plausibleScriptSrc
  if (!scriptSrc) return

  try {
    const origin = getRequestSiteOrigin()
    const pathname = new URL(request.url).pathname || '/'

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'User-Agent': request.headers.get('user-agent') || 'Unknown',
    }
    const clientIp = getClientIpFromRequest(request)
    if (clientIp) headers['X-Forwarded-For'] = clientIp

    const body = JSON.stringify({
      name: 'pageview',
      url: `${origin}${pathname}`,
      domain: new URL(origin).hostname,
      referrer: request.headers.get('referer') || null,
      props: {
        route: pathname,
        area: getAnalyticsArea(pathname),
        surface: getAnalyticsSurface(pathname),
        format: 'markdown',
      },
    })

    void fetch(resolvePlausibleEventUrl(scriptSrc), {
      method: 'POST',
      headers,
      body,
    }).catch(() => {})
  } catch {
    // Never block or fail the export response on analytics errors.
  }
}
