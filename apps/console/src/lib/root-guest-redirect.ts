import {
  getActiveProfileFeatures,
  getActiveProfileWithoutDebugOverride,
} from '@/lib/console-profiles'
import { hasConsoleSessionCookieFromHeader } from '@/lib/console-session-cookie'
import {
  isPreLaunchDocumentRequest,
  isPreLaunchModeEnabled,
} from '@/lib/pre-launch'
import { isLocalDevelopmentHost } from '@/lib/sentry/environment-shared'

export type RootGuestRedirectPath = '/init' | '/sign-in'

/** Client console entry after auth or from `/`. */
export const CONSOLE_ENTRY_PATH = '/' as const

/** Route id of `/`: the marketing homepage for guests, a console hop otherwise. */
export const ROOT_HOME_ROUTE_ID = '/_marketing/'

export type RootHomeLoaderData = { view: 'home' | 'console' }

export type RootDocumentRedirectPath = RootGuestRedirectPath

export type RootGuestRedirect = {
  url: URL
  status: 301 | 302
}

export function isRootRedirectPath(pathname: string | undefined): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  return normalized === '/'
}

/**
 * `/` (and legacy `/app`) are blank redirect hops (no marketing/console chrome)
 * unless `/` resolved to the guest homepage (see {@link isRootHomeMatch}).
 */
export function isConsoleRedirectHopPath(pathname: string | undefined): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  return normalized === '/' || normalized === '/app'
}

/** True when the rendered `/` match is showing the marketing homepage. */
export function isRootHomeMatch(
  matches: ReadonlyArray<{
    routeId?: string
    status?: string
    loaderData?: unknown
  }>,
): boolean {
  return matches.some(
    (match) =>
      match.routeId === ROOT_HOME_ROUTE_ID &&
      match.status === 'success' &&
      (match.loaderData as RootHomeLoaderData | undefined)?.view === 'home',
  )
}

function isLocalSiteRequest(request: Request): boolean {
  try {
    return isLocalDevelopmentHost(new URL(request.url).hostname)
  } catch {
    return false
  }
}

function guestRedirectUrl(
  requestUrl: string,
  pathname: RootDocumentRedirectPath,
): URL {
  const base = new URL(requestUrl)
  const target = new URL(pathname, base)
  target.search = base.search
  return target
}

/**
 * Absolute Location headers must be https on production. Behind Cloudflare,
 * `request.url` is often `http://appwrite.io/...`, which produced
 * `Location: http://appwrite.io/...` and dropped Secure session cookies.
 */
export function rootRedirectLocationPath(
  redirectUrl: URL,
): string {
  return `${redirectUrl.pathname}${redirectUrl.search}`
}

/**
 * 301 for `/sign-in` when marketing is off. Crawlers do not send a console
 * session cookie, so this is the URL they should index; a 302 would leave `/`
 * in the index with no content.
 *
 * 302 for pre-launch `/init` only.
 */
export function getRootGuestRedirectStatus(
  pathname: RootDocumentRedirectPath,
): 301 | 302 {
  return pathname === '/init' ? 302 : 301
}

/**
 * `/` requests the server cannot classify as guest: a console session cookie
 * is present, or the host is localhost (where the Appwrite SDK often keeps the
 * session in `localStorage` via `cookieFallback`). These render `/` as a
 * client-only hop so the loader can call `account.get` and route to the console.
 */
export function isRootConsoleHopRequest(request: Request): boolean {
  if (isLocalSiteRequest(request)) return true
  return hasConsoleSessionCookieFromHeader(request.headers.get('cookie'))
}

/**
 * Where `/` document requests should go before the SPA boots.
 *
 * Guests: no redirect; `/` server-renders the marketing homepage. Pre-launch
 * guests go to `/init`, and `/sign-in` when marketing is off. Session cookie:
 * no redirect; the client loader on `/` calls `account.get` (HttpOnly cookies
 * are invisible to JS) and routes to the console.
 *
 * Skipped on localhost/loopback (see {@link isRootConsoleHopRequest}).
 */
export function resolveRootGuestRedirect(
  request: Request,
  pathname?: string,
): RootGuestRedirect | null {
  if (process.env.TSS_PRERENDERING === 'true') return null

  const path =
    pathname ??
    (() => {
      try {
        return new URL(request.url).pathname
      } catch {
        return '/'
      }
    })()

  if (!isRootRedirectPath(path)) return null
  if (isLocalSiteRequest(request)) return null
  if (!isPreLaunchDocumentRequest(request)) return null

  const cookieHeader = request.headers.get('cookie')
  if (hasConsoleSessionCookieFromHeader(cookieHeader)) {
    if (isPreLaunchModeEnabled(cookieHeader)) {
      return {
        url: guestRedirectUrl(request.url, '/init'),
        status: getRootGuestRedirectStatus('/init'),
      }
    }
    return null
  }

  const dest: RootGuestRedirectPath | null = isPreLaunchModeEnabled(cookieHeader)
    ? '/init'
    : getActiveProfileWithoutDebugOverride().features.marketing
      ? null
      : '/sign-in'
  if (!dest) return null

  return {
    url: guestRedirectUrl(request.url, dest),
    status: getRootGuestRedirectStatus(dest),
  }
}

/**
 * Mirrors {@link resolveRootGuestRedirect} for client loaders (no Request).
 * `null` means the guest stays on `/` and sees the homepage.
 */
export function resolveRootGuestRedirectPathname(): RootGuestRedirectPath | null {
  if (isPreLaunchModeEnabled()) {
    return '/init'
  }
  const { marketing } = getActiveProfileFeatures()
  if (!marketing) {
    return '/sign-in'
  }
  return null
}
