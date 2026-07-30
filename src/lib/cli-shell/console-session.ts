import { getBaseEndpoint, sdk } from '@/lib/appwrite/sdk'
import { BROWSER_PROXY_SESSION_COOKIE } from './constants'

const CONSOLE_SESSION_KEY = 'a_session_console'

export type CliAuthMode = 'cookie' | 'browser-proxy'

export type ResolvedCliAuth = {
  sessionCookie: string
  mode: CliAuthMode
}

function formatCliCookieValue(sessionSecret: string): string {
  const trimmed = sessionSecret.trim()
  if (!trimmed) return trimmed
  if (trimmed.startsWith(`${CONSOLE_SESSION_KEY}=`)) {
    return trimmed.split(';')[0]?.trim() ?? trimmed
  }
  return `${CONSOLE_SESSION_KEY}=${trimmed}`
}

function readCookieFallbackSecret(): string | null {
  if (typeof window === 'undefined') return null

  try {
    const cookieFallback = window.localStorage.getItem('cookieFallback')
    if (!cookieFallback) return null
    const parsed = JSON.parse(cookieFallback) as Record<string, string>
    const session = parsed[CONSOLE_SESSION_KEY]
    if (typeof session === 'string' && session.trim()) {
      return session.trim()
    }
  } catch {
    /* private mode / invalid JSON */
  }

  return null
}

/** Read session material from the live Console SDK client config. */
function getSdkConsoleSessionMaterial(): string | null {
  try {
    const config = sdk.forConsole.client.config as {
      cookie?: string
      session?: string
    }
    if (typeof config.cookie === 'string' && config.cookie.trim()) {
      return config.cookie.trim()
    }
    if (typeof config.session === 'string' && config.session.trim()) {
      return config.session.trim()
    }
  } catch {
    /* noop */
  }
  return null
}

/** Read the console session secret used by the Appwrite SDK (cookieFallback or document cookie). */
export function getConsoleSessionCookie(): string | null {
  const fallback = readCookieFallbackSecret()
  if (fallback) return fallback

  if (typeof window === 'undefined') return null

  const match = document.cookie.match(
    new RegExp(`${CONSOLE_SESSION_KEY}=([^;]+)`),
  )
  if (match?.[1]) {
    try {
      return decodeURIComponent(match[1]).trim()
    } catch {
      return match[1].trim()
    }
  }

  const sdkMaterial = getSdkConsoleSessionMaterial()
  if (sdkMaterial) {
    if (sdkMaterial.startsWith(`${CONSOLE_SESSION_KEY}=`)) {
      const [, secret = ''] = sdkMaterial.split('=', 2)
      return secret.split(';')[0]?.trim() || null
    }
    return sdkMaterial
  }

  return null
}

/**
 * Cookie string for Appwrite CLI prefs (`a_session_console=...`).
 * The CLI stores the full cookie header fragment, not just the secret value.
 */
export function getConsoleCliCookie(): string | null {
  const session = getConsoleSessionCookie()
  if (!session) return null
  return formatCliCookieValue(session)
}

/**
 * Resolve CLI auth for the signed-in console user.
 * Falls back to browser cookie forwarding when the session is httpOnly-only.
 */
export async function resolveConsoleCliAuth(): Promise<ResolvedCliAuth | null> {
  const syncCookie = getConsoleCliCookie()
  if (syncCookie) {
    return { sessionCookie: syncCookie, mode: 'cookie' }
  }

  // httpOnly console sessions cannot be copied into CLI prefs. The fetch bridge
  // forwards browser cookies for almostnode CLI requests. Auth is already
  // verified by the shared React Query account fetch - no extra account.get here.
  return {
    sessionCookie: BROWSER_PROXY_SESSION_COOKIE,
    mode: 'browser-proxy',
  }
}

/** Build ~/.appwrite/prefs.json content for the in-browser CLI session. */
export function buildCliPrefsJson(options: {
  consoleEndpoint: string
  email: string
  sessionCookie: string
  sessionId?: string
}): string {
  const sessionId = options.sessionId ?? `console-web-${Date.now()}`
  return JSON.stringify(
    {
      current: sessionId,
      [sessionId]: {
        endpoint: options.consoleEndpoint || getBaseEndpoint(),
        email: options.email,
        cookie: formatCliCookieValue(options.sessionCookie),
      },
    },
    null,
    2,
  )
}

/** Minimal appwrite.config.json for the active project. */
export function buildAppwriteConfigJson(options: {
  projectId: string
  endpoint: string
  organizationId?: string
}): string {
  const organizationId = options.organizationId?.trim()
  return JSON.stringify(
    {
      projectId: options.projectId,
      ...(organizationId ? { organizationId } : {}),
      endpoint: options.endpoint,
      functions: [],
      sites: [],
      tablesDB: [],
      tables: [],
      buckets: [],
      teams: [],
      topics: [],
    },
    null,
    2,
  )
}
