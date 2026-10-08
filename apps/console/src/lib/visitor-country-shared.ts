/**
 * Bun-safe visitor country helpers (no path aliases / Vite-only constructs).
 *
 * Used by `server.ts` to stamp country into prerendered HTML, and by the
 * Vite app for cookies, request headers, and React Query seeding.
 */

export const SSR_VISITOR_COUNTRY_WINDOW_KEY = '__SSR_VISITOR_COUNTRY__'

export const VISITOR_COUNTRY_COOKIE_NAME = 'console_visitor_country'

/** Debug mock ISO country so SSR/prerender matches the pink-menu override. */
export const MOCK_LOCALE_COUNTRY_COOKIE_NAME = 'debug_locale_country'

/**
 * Fast geo headers. Cloudflare's `CF-IPCountry` is the primary signal.
 * `XX` / `T1` are treated as unknown by `normalizeVisitorCountryCode`.
 */
export const VISITOR_COUNTRY_HEADERS = [
  'cf-ipcountry',
  'cloudfront-viewer-country',
  'x-appwrite-country',
  'x-visitor-country',
] as const

const SSR_VISITOR_COUNTRY_ASSIGNMENT = /window\.__SSR_VISITOR_COUNTRY__=[^;]*/g

export type VisitorCountryRequestLike = {
  headers: {
    get(name: string): string | null
  }
}

function nonempty(value: string | null | undefined): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

export function normalizeVisitorCountryCode(
  countryCode: string | null | undefined,
): string | null {
  const normalized = countryCode?.trim().toUpperCase()
  if (!normalized || normalized.length !== 2) return null
  if (normalized === 'XX' || normalized === 'T1') return null
  if (!/^[A-Z]{2}$/.test(normalized)) return null
  return normalized
}

export function readCookieValue(
  cookieSource: string | null | undefined,
  name: string,
): string | null {
  if (!cookieSource) return null
  const pattern = new RegExp(`(?:^|;\\s*)${name}=([^;]*)`)
  const match = cookieSource.match(pattern)
  if (!match?.[1]) return null
  try {
    return nonempty(decodeURIComponent(match[1]))
  } catch {
    return nonempty(match[1])
  }
}

export function getVisitorCountryFromHeaders(
  headers: VisitorCountryRequestLike['headers'],
): string | null {
  for (const name of VISITOR_COUNTRY_HEADERS) {
    const country = normalizeVisitorCountryCode(headers.get(name))
    if (country) return country
  }
  return null
}

export function getMockLocaleCountryFromCookie(
  cookieSource: string | null | undefined,
): string | null {
  const raw = readCookieValue(cookieSource, MOCK_LOCALE_COUNTRY_COOKIE_NAME)
  if (!raw || raw.toLowerCase() === 'auto') return null
  return normalizeVisitorCountryCode(raw)
}

export function getStoredVisitorCountryFromCookie(
  cookieSource: string | null | undefined,
): string | null {
  return normalizeVisitorCountryCode(
    readCookieValue(cookieSource, VISITOR_COUNTRY_COOKIE_NAME),
  )
}

/**
 * Resolve visitor country without waiting on locale.get().
 * Debug mock cookie wins, then last-known visitor cookie, then CDN geo.
 */
export function resolveVisitorCountryFromRequest(
  request: VisitorCountryRequestLike,
): string | null {
  const cookie = request.headers.get('cookie')
  return (
    getMockLocaleCountryFromCookie(cookie) ??
    getStoredVisitorCountryFromCookie(cookie) ??
    getVisitorCountryFromHeaders(request.headers)
  )
}

export function injectSsrVisitorCountryIntoHtml(
  html: string,
  countryCode: string | null,
): string {
  const assignment = `window.${SSR_VISITOR_COUNTRY_WINDOW_KEY}=`
  const value = JSON.stringify(countryCode)
  if (!html.includes(assignment)) return html
  return html.replace(SSR_VISITOR_COUNTRY_ASSIGNMENT, `${assignment}${value}`)
}

export function buildVisitorCountryCookie(countryCode: string | null): {
  name: string
  value: string
  clear: boolean
} {
  const normalized = normalizeVisitorCountryCode(countryCode)
  return {
    name: VISITOR_COUNTRY_COOKIE_NAME,
    value: normalized ?? '',
    clear: !normalized,
  }
}

export function buildMockLocaleCountryCookie(countryCode: string | null): {
  name: string
  value: string
  clear: boolean
} {
  const normalized = normalizeVisitorCountryCode(countryCode)
  return {
    name: MOCK_LOCALE_COUNTRY_COOKIE_NAME,
    value: normalized ?? '',
    clear: !normalized,
  }
}
