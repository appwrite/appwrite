/**
 * Visitor IP from Cloudflare Transform Rule header `X-CDN-Client-IP` (`ip.src`).
 *
 * Bun-safe (no path aliases / Vite-only constructs) so `server-analytics.ts`
 * can import it when loaded raw from `server.ts`.
 *
 * Do not fall back to `cf-connecting-ip` / `x-real-ip` / `x-forwarded-for`:
 * those can carry the wrong hop and pollute Plausible country stats (e.g. our
 * German origin). If every IP header is missing, callers may pass the runtime
 * request IP (`getRequestIP()` / `request.ip`) as a last resort.
 */
export const CLIENT_IP_HEADER = 'x-cdn-client-ip'

/** Related hop headers shown in debug only. Never used to resolve the IP. */
export const CLIENT_IP_DEBUG_HEADERS = [
  CLIENT_IP_HEADER,
  'cf-connecting-ip',
  'x-real-ip',
  'x-forwarded-for',
] as const

export type ClientIpDebugHeaderName = (typeof CLIENT_IP_DEBUG_HEADERS)[number]

export type ClientIpSource = typeof CLIENT_IP_HEADER | 'runtime'

export type ClientIpSnapshot = {
  /** Canonical visitor IP: header first, then runtime request IP. */
  ip: string | null
  source: ClientIpSource | null
  /** IP attached by the runtime (`request.ip` / `getRequestIP()`). */
  runtimeIp: string | null
  headers: Record<ClientIpDebugHeaderName, string | null>
}

/** Injected during SSR so the debug menu can compare against the document request. */
export const SSR_CLIENT_IP_WINDOW_KEY = '__SSR_CLIENT_IP__'

declare global {
  interface Window {
    __SSR_CLIENT_IP__?: ClientIpSnapshot | null
  }
}

type RequestWithRuntimeIp = Request & {
  ip?: unknown
  context?: { clientAddress?: unknown }
}

function nonempty(value: unknown): string | null {
  return typeof value === 'string' && value.trim() ? value.trim() : null
}

/** IP the runtime attached to this request. Does not read hop headers. */
export function getRuntimeRequestIp(
  request: Request,
  runtimeIp?: string | null,
): string | null {
  const fromCaller = nonempty(runtimeIp)
  if (fromCaller) return fromCaller

  const req = request as RequestWithRuntimeIp
  return nonempty(req.ip) || nonempty(req.context?.clientAddress)
}

export function getClientIpFromRequest(
  request: Request,
  runtimeIp?: string | null,
): string | null {
  return (
    nonempty(request.headers.get(CLIENT_IP_HEADER)) ||
    getRuntimeRequestIp(request, runtimeIp)
  )
}

export function getClientIpSnapshotFromRequest(
  request: Request,
  runtimeIp?: string | null,
): ClientIpSnapshot {
  const headers = {} as Record<ClientIpDebugHeaderName, string | null>
  for (const name of CLIENT_IP_DEBUG_HEADERS) {
    headers[name] = nonempty(request.headers.get(name))
  }
  const headerIp = headers[CLIENT_IP_HEADER]
  const resolvedRuntimeIp = getRuntimeRequestIp(request, runtimeIp)
  const ip = headerIp || resolvedRuntimeIp
  return {
    ip,
    source: headerIp ? CLIENT_IP_HEADER : ip ? 'runtime' : null,
    runtimeIp: resolvedRuntimeIp,
    headers,
  }
}
