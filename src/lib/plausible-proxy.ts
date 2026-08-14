/**
 * First-party Plausible proxy paths and server helpers.
 *
 * Paths are intentionally short and non-descriptive so common adblock lists
 * (which match "plausible", "analytics", "stats", etc.) are less likely to
 * block them. See https://plausible.io/docs/proxy/introduction
 */

import { getClientIpFromRequest } from './client-ip'

export { getClientIpFromRequest }

/** First-party script URL served by the app (proxies upstream Plausible JS). */
export const PLAUSIBLE_PROXY_SCRIPT_PATH = '/r/v.js'

/** First-party event endpoint (proxies to Plausible /api/event). */
export const PLAUSIBLE_PROXY_EVENT_PATH = '/r/e'

const PLAUSIBLE_ORIGIN_FALLBACK = 'https://plausible.io'

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
    return new Response(body, {
      status: 200,
      headers: {
        'Content-Type':
          upstream.headers.get('content-type') ||
          'application/javascript; charset=utf-8',
        'Cache-Control': 'public, max-age=86400, stale-while-revalidate=604800',
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
