import { WEBSITE_ACCESS_COOKIE_NAME } from '../../src/lib/website-access'

/**
 * Soft-launch gate cookie. Required for every e2e request so middleware and
 * WebsiteAccessGate do not redirect to /access.
 */
export const websiteAccessCookie = {
  name: WEBSITE_ACCESS_COOKIE_NAME,
  value: '1',
  domain: 'localhost',
  path: '/',
  /** Session cookie; Playwright uses -1 for non-persistent. */
  expires: -1,
  httpOnly: false,
  secure: false,
  sameSite: 'Lax' as const,
}

export const websiteAccessStorageState = {
  cookies: [websiteAccessCookie],
  origins: [] as unknown[],
}

type CookieLike = { name?: string }

/** Ensure a storage-state blob includes the soft-launch access cookie. */
export function withWebsiteAccessCookie<T extends { cookies?: unknown[] }>(
  state: T,
): T {
  const cookies = Array.isArray(state.cookies) ? [...state.cookies] : []
  const hasAccess = cookies.some(
    (cookie) => (cookie as CookieLike).name === WEBSITE_ACCESS_COOKIE_NAME,
  )
  if (!hasAccess) {
    cookies.push(websiteAccessCookie)
  }
  return { ...state, cookies }
}
