export const WEBSITE_ACCESS_COOKIE_NAME = 'aw_website_access'
export const WEBSITE_ACCESS_PASSWORD = 'Appwrite2'

/**
 * Demo / soft-launch password gate.
 * Unset or unrecognized `VITE_CONSOLE_WEBSITE_ACCESS` → enabled.
 * `false` / `0` / `disabled` turns the gate off (middleware, boot cover, UI).
 */
export function isWebsiteAccessEnabled(
  envValue: string | null | undefined,
): boolean {
  const normalized = (envValue ?? '').toLowerCase().trim()
  if (normalized === 'false' || normalized === '0' || normalized === 'disabled')
    return false
  return true
}

const COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365 // 1 year

const COOKIE_PATTERN = new RegExp(
  `(?:^|;\\s*)${WEBSITE_ACCESS_COOKIE_NAME}=([^;]*)`,
)

export function hasWebsiteAccessCookieFromHeader(
  cookieHeader: string | null | undefined,
): boolean {
  if (!cookieHeader) return false
  const match = cookieHeader.match(COOKIE_PATTERN)
  return Boolean(match?.[1]?.trim())
}

export function hasWebsiteAccessCookie(): boolean {
  if (typeof document === 'undefined') return false
  return hasWebsiteAccessCookieFromHeader(document.cookie)
}

export function setWebsiteAccessCookie(): void {
  if (typeof document === 'undefined') return
  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  document.cookie = `${WEBSITE_ACCESS_COOKIE_NAME}=1; path=/; max-age=${COOKIE_MAX_AGE_SECONDS}; SameSite=Lax${secure}`
}

/** Soft-launch gate: every page except `/access` and public affiliate invite short links. */
export function isWebsiteAccessProtectedPath(
  pathname: string | null | undefined,
): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  if (normalized === '/access') return false
  // `/i/{linkId}` must redirect to the API without the soft-launch password.
  if (normalized === '/i' || normalized.startsWith('/i/')) return false
  return true
}

/**
 * Client gate should cover `/access` itself (password form) plus every
 * protected path. Affiliate short links stay exempt.
 */
export function shouldShowWebsiteAccessGate(
  pathname: string | null | undefined,
): boolean {
  const normalized = (pathname ?? '/').replace(/\/+$/, '') || '/'
  if (normalized === '/i' || normalized.startsWith('/i/')) return false
  return true
}
