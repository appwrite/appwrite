import { BROWSER_PROXY_SESSION_COOKIE } from './constants'

let originalFetch: typeof globalThis.fetch | null = null
let activeEndpointPrefixes: string[] = []

function resolveRequestUrl(input: RequestInfo | URL): string {
  if (typeof input === 'string') return input
  if (input instanceof URL) return input.href
  return input.url
}

function isAppwriteApiRequest(url: string, endpointPrefixes: string[]): boolean {
  try {
    const parsed = new URL(url)
    if (parsed.pathname.startsWith('/v1/')) return true
    return endpointPrefixes.some((prefix) => {
      try {
        const endpointUrl = new URL(prefix)
        return parsed.origin === endpointUrl.origin
      } catch {
        return url.startsWith(prefix)
      }
    })
  } catch {
    return endpointPrefixes.some((prefix) => url.startsWith(prefix))
  }
}

function buildBridgedHeaders(init?: RequestInit): Headers {
  const headers = new Headers(init?.headers)
  const cookie = headers.get('cookie') ?? headers.get('Cookie')
  if (cookie?.includes('__browser_session__')) {
    headers.delete('cookie')
    headers.delete('Cookie')
  }

  if (typeof window !== 'undefined') {
    try {
      const cookieFallback = window.localStorage.getItem('cookieFallback')
      if (cookieFallback && !headers.has('X-Fallback-Cookies')) {
        headers.set('X-Fallback-Cookies', cookieFallback)
      }
    } catch {
      /* private mode */
    }
  }

  return headers
}

/**
 * Route Appwrite API requests through the browser fetch stack with session cookies.
 * almostnode's HTTP shim uses global fetch without credentials; this restores auth
 * for httpOnly console sessions when the CLI prefs hold a proxy sentinel cookie.
 */
export function installCliFetchBridge(endpointPrefixes: string[]): void {
  if (typeof window === 'undefined') return

  const normalized = endpointPrefixes
    .map((value) => value.trim())
    .filter(Boolean)
  if (normalized.length === 0) return

  activeEndpointPrefixes = normalized

  if (!originalFetch) {
    originalFetch = globalThis.fetch.bind(globalThis)
    globalThis.fetch = async (input, init) => {
      const url = resolveRequestUrl(input)
      if (!isAppwriteApiRequest(url, activeEndpointPrefixes)) {
        return originalFetch!(input, init)
      }

      const headers = buildBridgedHeaders(init)
      return originalFetch!(input, {
        ...init,
        headers,
        credentials: 'include',
      })
    }
  }
}

export function removeCliFetchBridge(): void {
  if (typeof window === 'undefined' || !originalFetch) return
  globalThis.fetch = originalFetch
  originalFetch = null
  activeEndpointPrefixes = []
}
