/**
 * Server-side Plausible pageview tracking for non-HTML endpoints (markdown
 * exports, llms.txt, robots.txt, discovery JSON) that are fetched by LLMs,
 * crawlers, and scripts which never execute the client analytics script.
 *
 * Mirrors the first-party proxy behavior: events are sent to the upstream
 * Plausible /api/event endpoint with the visitor IP and user agent forwarded.
 * These are all public pages, so the full concrete path of the requested
 * page is reported (e.g. /blog/post/my-post.md), matching client-side
 * tracking for public pages.
 *
 * Loaded raw by Bun from `server.ts` (not Vite-bundled). Must stay free of
 * Vite-only constructs (`import.meta.env`, `?url` imports, path aliases).
 */
import { getAnalyticsArea, getAnalyticsSurface } from './analytics-route.ts'
import { getClientIpFromRequest } from './client-ip.ts'
import { resolvePlausibleEventUrl } from './plausible-proxy.ts'
import { readRuntimeConfigFromEnv } from './runtime-config-shared.ts'

export type ServerPageviewFormat = 'markdown' | 'text' | 'json'

function inferServerPageviewFormat(pathname: string): ServerPageviewFormat {
  if (pathname.endsWith('.json')) return 'json'
  if (pathname.endsWith('.md')) return 'markdown'
  // llms.txt / llms-full.txt / docs/llms.txt are Markdown indexes/dumps.
  if (pathname.includes('llms')) return 'markdown'
  if (
    pathname.endsWith('.txt') ||
    pathname.endsWith('.sh') ||
    pathname.endsWith('.ps1') ||
    pathname === '/robots.txt' ||
    pathname.endsWith('/robots.txt')
  ) {
    return 'text'
  }
  return 'markdown'
}

type TrackServerPageviewOptions = {
  /** Override inferred content format for Plausible props. */
  format?: ServerPageviewFormat
}

/** Fire-and-forget: analytics must never delay or break the response. */
export function trackServerPageview(
  request: Request,
  options: TrackServerPageviewOptions = {},
): void {
  if (process.env.TSS_PRERENDERING === 'true') return

  const scriptSrc = readRuntimeConfigFromEnv(process.env).plausibleScriptSrc
  if (!scriptSrc) return

  try {
    const clientIp = getClientIpFromRequest(request)
    // Skip when we cannot forward a real visitor IP. Otherwise Plausible would
    // geo-locate our app server (Germany) and inflate country stats.
    if (!clientIp) return

    const url = new URL(request.url)
    const origin = url.origin
    const pathname = url.pathname || '/'
    const format = options.format ?? inferServerPageviewFormat(pathname)

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'User-Agent': request.headers.get('user-agent') || 'Unknown',
      'X-Forwarded-For': clientIp,
    }

    const body = JSON.stringify({
      name: 'pageview',
      url: `${origin}${pathname}`,
      domain: url.hostname,
      referrer: request.headers.get('referer') || null,
      props: {
        route: pathname,
        area: getAnalyticsArea(pathname),
        surface: getAnalyticsSurface(pathname),
        format,
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
