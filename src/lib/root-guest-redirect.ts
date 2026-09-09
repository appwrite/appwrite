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

export type RootGuestRedirectPath = '/init' | '/sign-in' | '/home'

export type RootGuestRedirect = {
  url: URL
  status: 301 | 302
}

export function isRootRedirectPath(pathname: string | undefined): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  return normalized === '/'
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
  pathname: RootGuestRedirectPath,
): URL {
  const base = new URL(requestUrl)
  const target = new URL(pathname, base)
  target.search = base.search
  return target
}

/**
 * 301 for the stable public landing (`/home`, and `/sign-in` when marketing is
 * off). Crawlers do not send a console session cookie, so this is the URL
 * they should index; a 302 would leave `/` in the index with no content.
 *
 * 302 only for pre-launch `/init`, which is a temporary lock.
 */
export function getRootGuestRedirectStatus(
  pathname: RootGuestRedirectPath,
): 301 | 302 {
  return pathname === '/init' ? 302 : 301
}

/**
 * Where unsigned `/` document requests should go before the SPA boots.
 * Returns null when a console session cookie is present so logged-in users
 * keep the client org redirect in `_public/index.tsx`.
 *
 * Skipped on localhost/loopback: the Appwrite SDK often stores the session in
 * `localStorage` (`cookieFallback`) instead of an HTTP cookie, so only the
 * client loader can tell guest vs signed-in. Production domains use the cookie.
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
  if (hasConsoleSessionCookieFromHeader(request.headers.get('cookie'))) {
    return null
  }

  const dest: RootGuestRedirectPath = isPreLaunchModeEnabled(
    request.headers.get('cookie'),
  )
    ? '/init'
    : getActiveProfileWithoutDebugOverride().features.marketing
      ? '/home'
      : '/sign-in'

  return {
    url: guestRedirectUrl(request.url, dest),
    status: getRootGuestRedirectStatus(dest),
  }
}

/** Mirrors {@link resolveRootGuestRedirect} for client loaders (no Request). */
export function resolveRootGuestRedirectPathname(): RootGuestRedirectPath {
  if (isPreLaunchModeEnabled()) {
    return '/init'
  }
  const { marketing } = getActiveProfileFeatures()
  if (!marketing) {
    return '/sign-in'
  }
  return '/home'
}
