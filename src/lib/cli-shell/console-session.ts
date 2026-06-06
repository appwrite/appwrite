import { getBaseEndpoint } from '@/lib/appwrite/sdk'

const CONSOLE_SESSION_KEY = 'a_session_console'

/** Read the console session secret used by the Appwrite SDK (cookieFallback or document cookie). */
export function getConsoleSessionCookie(): string | null {
  if (typeof window === 'undefined') return null

  try {
    const cookieFallback = window.localStorage.getItem('cookieFallback')
    if (cookieFallback) {
      const parsed = JSON.parse(cookieFallback) as Record<string, string>
      const session = parsed[CONSOLE_SESSION_KEY]
      if (typeof session === 'string' && session.trim()) {
        return session.trim()
      }
    }
  } catch {
    /* private mode / invalid JSON */
  }

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

  return null
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
        cookie: options.sessionCookie,
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
}): string {
  return JSON.stringify(
    {
      projectId: options.projectId,
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
