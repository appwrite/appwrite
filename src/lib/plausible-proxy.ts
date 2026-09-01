/**
 * First-party Plausible proxy paths and server helpers.
 *
 * Paths are intentionally short and non-descriptive so common adblock lists
 * (which match "plausible", "analytics", "stats", etc.) are less likely to
 * block them. See https://plausible.io/docs/proxy/introduction
 */

import { getClientIpFromRequest } from './client-ip'

export { getClientIpFromRequest }

/** First-party script path served by the app (proxies upstream Plausible JS). */
export const PLAUSIBLE_PROXY_SCRIPT_PATH = '/r/v.js'

/** First-party event endpoint (proxies to Plausible /api/event). */
export const PLAUSIBLE_PROXY_EVENT_PATH = '/r/e'

/**
 * Bump when Plausible mutates the same upstream script URL in place (e.g. site
 * domain rename). Combined with the upstream URL into the browser `?v=` param so
 * CDN/browser caches do not keep serving a stale embedded domain.
 */
export const PLAUSIBLE_SCRIPT_CACHE_VERSION = '3'

const PLAUSIBLE_ORIGIN_FALLBACK = 'https://plausible.io'

/** djb2 → base36; stable across server and browser, no crypto dependency. */
function hashCacheKey(input: string): string {
  let hash = 5381
  for (let i = 0; i < input.length; i++) {
    hash = (hash * 33) ^ input.charCodeAt(i)
  }
  return (hash >>> 0).toString(36)
}

/**
 * Browser-facing first-party script URL with a cache-busting query param.
 * Cloudflare caches by full URL, so bumping the version (or changing the
 * upstream script src) forces a fresh fetch through the proxy.
 */
export function buildPlausibleProxyScriptSrc(upstreamScriptSrc: string): string {
  if (!upstreamScriptSrc) return ''
  const v = hashCacheKey(
    `${upstreamScriptSrc}|${PLAUSIBLE_SCRIPT_CACHE_VERSION}`,
  )
  return `${PLAUSIBLE_PROXY_SCRIPT_PATH}?v=${v}`
}

export function resolvePlausibleEventUrl(scriptSrc: string): string {
  try {
    return new URL('/api/event', new URL(scriptSrc).origin).toString()
  } catch {
    return `${PLAUSIBLE_ORIGIN_FALLBACK}/api/event`
  }
}

export async function proxyPlausibleScript(
  scriptSrc: string,
): Promise<Response> {
  if (!scriptSrc) {
    return new Response('Not found', { status: 404 })
  }

  try {
    const upstream = await fetch(scriptSrc, {
      headers: { Accept: 'application/javascript, text/javascript, */*' },
      redirect: 'follow',
    })

    if (!upstream.ok) {
      return new Response('Upstream script unavailable', {
        status: upstream.status === 404 ? 404 : 502,
      })
    }

    const body = await upstream.arrayBuffer()
    // Keep CDN cache short: Plausible mutates the same script URL when the site
    // domain changes (e.g. new.appwrite.io → appwrite.io). A long
    // stale-while-revalidate window previously left browsers sending events
    // with the old embedded domain for days after the cutover.
    return new Response(body, {
      status: 200,
      headers: {
        'Content-Type':
          upstream.headers.get('content-type') ||
          'application/javascript; charset=utf-8',
        'Cache-Control': 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400',
      },
    })
  } catch {
    return new Response('Failed to proxy script', { status: 502 })
  }
}

export async function proxyPlausibleEvent(
  request: Request,
  eventUrl: string,
): Promise<Response> {
  if (!eventUrl) {
    return new Response('Not found', { status: 404 })
  }

  const clientIp = getClientIpFromRequest(request)
  // Never forward to Plausible without a visitor IP: the upstream request would
  // otherwise appear to come from this app server and pollute geo stats.
  if (!clientIp) {
    return new Response(null, { status: 204 })
  }

  const headers = new Headers()
  headers.set(
    'Content-Type',
    request.headers.get('content-type') || 'application/json',
  )
  headers.set(
    'User-Agent',
    request.headers.get('user-agent') || 'Unknown',
  )
  headers.set('X-Forwarded-For', clientIp)

  try {
    const upstream = await fetch(eventUrl, {
      method: 'POST',
      headers,
      body: await request.arrayBuffer(),
      redirect: 'manual',
    })

    return new Response(await upstream.arrayBuffer(), {
      status: upstream.status,
      headers: {
        'Content-Type':
          upstream.headers.get('content-type') || 'text/plain; charset=utf-8',
        'Cache-Control': 'no-store',
      },
    })
  } catch {
    return new Response('Failed to proxy event', { status: 502 })
  }
}
