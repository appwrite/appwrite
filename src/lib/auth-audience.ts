export type AuthAudience = 'console' | 'partner'

export const CONSOLE_AUTH_COVER = '/cover.avif'
export const PARTNER_AUTH_COVER = '/images/partners/auth-cover.avif'

export const PARTNER_AUTH_ENTRY_PATH = '/partner' as const
export const PARTNER_SIGN_IN_PATH = '/partner/sign-in' as const
export const PARTNER_SIGN_UP_PATH = '/partner/sign-up' as const

const AUTH_AUDIENCE_STORAGE_KEY = 'console.authAudience'

export function isPartnerAuthPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === PARTNER_AUTH_ENTRY_PATH ||
    normalized === PARTNER_SIGN_IN_PATH ||
    normalized === PARTNER_SIGN_UP_PATH
  )
}

export function authAudienceFromPathname(pathname: string): AuthAudience {
  return isPartnerAuthPath(pathname) ? 'partner' : 'console'
}

export function setAuthAudience(audience: AuthAudience): void {
  if (typeof window === 'undefined') return
  try {
    sessionStorage.setItem(AUTH_AUDIENCE_STORAGE_KEY, audience)
  } catch {
    // sessionStorage can be unavailable in some browser modes.
  }
}

export function getStoredAuthAudience(): AuthAudience {
  if (typeof window === 'undefined') return 'console'
  try {
    return sessionStorage.getItem(AUTH_AUDIENCE_STORAGE_KEY) === 'partner'
      ? 'partner'
      : 'console'
  } catch {
    return 'console'
  }
}

/** Pathname and `?audience=partner` win; otherwise use stored audience (MFA, recovery). */
export function resolveAuthAudience(pathname?: string): AuthAudience {
  if (pathname && isPartnerAuthPath(pathname)) return 'partner'
  if (typeof window !== 'undefined') {
    const path = pathname ?? window.location.pathname
    if (isPartnerAuthPath(path)) return 'partner'
    try {
      if (new URLSearchParams(window.location.search).get('audience') === 'partner') {
        return 'partner'
      }
    } catch {
      // Ignore malformed search strings.
    }
  }
  return getStoredAuthAudience()
}

export function getAuthSignInPath(
  _audience?: AuthAudience,
): '/sign-in' {
  return '/sign-in'
}

export function getAuthSignUpPath(
  _audience?: AuthAudience,
): '/sign-up' {
  return '/sign-up'
}

export function getAuthEmailSearch(
  audience: AuthAudience,
  redirect?: string,
): { redirect?: string; audience?: 'partner' } | undefined {
  const search: { redirect?: string; audience?: 'partner' } = {}
  if (redirect) search.redirect = redirect
  if (audience === 'partner') search.audience = 'partner'
  return Object.keys(search).length > 0 ? search : undefined
}
