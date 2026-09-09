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

function isRootPath(pathname: string | undefined): boolean {
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

/**
 * Where unsigned `/` document requests should go before the SPA boots.
 * Returns null when a console session cookie is present so logged-in users
 * keep the client org redirect in `_public/index.tsx`.
 *
 * Skipped on localhost/loopback: the Appwrite SDK often stores the session in
 * `localStorage` (`cookieFallback`) instead of an HTTP cookie, so only the
 * client loader can tell guest vs signed-in. Production domains use the cookie.
 */
export function resolveRootGuestRedirectUrl(
  request: Request,
  pathname?: string,
): URL | null {
  const path =
    pathname ??
    (() => {
      try {
        return new URL(request.url).pathname
      } catch {
        return '/'
      }
    })()

  if (!isRootPath(path)) return null
  if (isLocalSiteRequest(request)) return null
  if (!isPreLaunchDocumentRequest(request)) return null
  if (hasConsoleSessionCookieFromHeader(request.headers.get('cookie'))) {
    return null
  }

  const base = new URL(request.url)
  if (isPreLaunchModeEnabled(request.headers.get('cookie'))) {
    return new URL('/init', base)
  }

  const { marketing } = getActiveProfileWithoutDebugOverride().features
  if (!marketing) {
    return new URL('/sign-in', base)
  }

  return new URL('/home', base)
}

/** Mirrors {@link resolveRootGuestRedirectUrl} for client loaders (no Request). */
export function resolveRootGuestRedirectPathname(): '/init' | '/sign-in' | '/home' {
  if (isPreLaunchModeEnabled()) {
    return '/init'
  }
  const { marketing } = getActiveProfileFeatures()
  if (!marketing) {
    return '/sign-in'
  }
  return '/home'
}
