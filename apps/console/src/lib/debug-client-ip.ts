import {
  SSR_CLIENT_IP_WINDOW_KEY,
  type ClientIpSnapshot,
} from '@/lib/client-ip'

export type { ClientIpSnapshot }

/** First-party endpoint that returns `getClientIpSnapshotFromRequest` for this request. */
export const DEBUG_CLIENT_IP_PATH = '/debug/ip'

export type BrowserPublicIpSource = 'cdn-cgi/trace' | 'ipify'

export type BrowserPublicIpResult = {
  ip: string | null
  source: BrowserPublicIpSource | null
  error: string | null
}

export function readSsrClientIpSnapshot(): ClientIpSnapshot | null {
  if (typeof window === 'undefined') return null
  return window[SSR_CLIENT_IP_WINDOW_KEY] ?? null
}

export function parseCloudflareTraceIp(text: string): string | null {
  const match = text.match(/^ip=(.+)$/m)
  const ip = match?.[1]?.trim()
  return ip || null
}

function normalizeIp(ip: string): string {
  return ip.trim().toLowerCase()
}

export function ipsMatch(left: string | null, right: string | null): boolean {
  if (!left || !right) return false
  return normalizeIp(left) === normalizeIp(right)
}

async function fetchWithTimeout(
  url: string,
  timeoutMs = 4000,
): Promise<Response> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)
  try {
    return await fetch(url, {
      cache: 'no-store',
      credentials: 'omit',
      signal: controller.signal,
    })
  } finally {
    clearTimeout(timer)
  }
}

/**
 * Public IP as the browser sees it, independent of our SSR hop.
 * Prefers same-origin Cloudflare `/cdn-cgi/trace`, then ipify.
 */
export async function fetchBrowserPublicIp(): Promise<BrowserPublicIpResult> {
  try {
    const traceResponse = await fetchWithTimeout(
      new URL('/cdn-cgi/trace', window.location.origin).toString(),
    )
    if (traceResponse.ok) {
      const ip = parseCloudflareTraceIp(await traceResponse.text())
      if (ip) return { ip, source: 'cdn-cgi/trace', error: null }
    }
  } catch {
    // Local/dev or non-Cloudflare origins have no /cdn-cgi/trace.
  }

  try {
    const ipifyResponse = await fetchWithTimeout(
      'https://api.ipify.org?format=json',
    )
    if (ipifyResponse.ok) {
      const payload = (await ipifyResponse.json()) as { ip?: unknown }
      const ip = typeof payload.ip === 'string' ? payload.ip.trim() : ''
      if (ip) return { ip, source: 'ipify', error: null }
    }
    return {
      ip: null,
      source: null,
      error: `ipify HTTP ${ipifyResponse.status}`,
    }
  } catch (error) {
    return {
      ip: null,
      source: null,
      error: error instanceof Error ? error.message : 'Browser IP lookup failed',
    }
  }
}

export async function fetchLiveClientIpSnapshot(): Promise<ClientIpSnapshot> {
  const response = await fetch(DEBUG_CLIENT_IP_PATH, {
    cache: 'no-store',
    credentials: 'same-origin',
  })
  if (!response.ok) {
    throw new Error(`Debug IP endpoint HTTP ${response.status}`)
  }
  return (await response.json()) as ClientIpSnapshot
}
