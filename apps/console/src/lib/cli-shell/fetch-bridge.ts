import {
  fetchConsoleAccount,
  getConsoleAccountFromSingleton,
} from '@/lib/console-account-get'

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

function isConsoleAccountGetRequest(url: string, method?: string): boolean {
  const normalizedMethod = (method ?? 'GET').toUpperCase()
  if (normalizedMethod !== 'GET') return false
  try {
    const parsed = new URL(url)
    return (
      parsed.pathname === '/v1/account' ||
      parsed.pathname.endsWith('/v1/account')
    )
  } catch {
    return /\/v1\/account\/?(?:\?|$)/.test(url)
  }
}

async function resolveBridgedConsoleAccountResponse(): Promise<Response> {
  const cached = getConsoleAccountFromSingleton()
  const account = cached ?? (await fetchConsoleAccount())
  return new Response(JSON.stringify(account), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
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
    globalThis.fetch = (async (input, init) => {
      const url = resolveRequestUrl(input)
      if (!isAppwriteApiRequest(url, activeEndpointPrefixes)) {
        return originalFetch!(input, init)
      }

      if (isConsoleAccountGetRequest(url, init?.method)) {
        try {
          return await resolveBridgedConsoleAccountResponse()
        } catch {
          /* fall through to network fetch for auth errors */
        }
      }

      const headers = buildBridgedHeaders(init)
      return originalFetch!(input, {
        ...init,
        headers,
        credentials: 'include',
      })
    }) as typeof fetch
  }
}

export function removeCliFetchBridge(): void {
  if (typeof window === 'undefined' || !originalFetch) return
  globalThis.fetch = originalFetch
  originalFetch = null
  activeEndpointPrefixes = []
}
