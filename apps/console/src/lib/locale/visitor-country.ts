import { normalizeCountryCode } from '@/lib/pricing/start-plan'
import {
  MOCK_LOCALE_COUNTRY_COOKIE_NAME,
  SSR_VISITOR_COUNTRY_WINDOW_KEY,
  VISITOR_COUNTRY_COOKIE_NAME,
  getMockLocaleCountryFromCookie,
  getStoredVisitorCountryFromCookie,
  type VisitorCountryRequestLike,
  resolveVisitorCountryFromRequest,
} from '@/lib/visitor-country-shared'

export {
  MOCK_LOCALE_COUNTRY_COOKIE_NAME,
  SSR_VISITOR_COUNTRY_WINDOW_KEY,
  VISITOR_COUNTRY_COOKIE_NAME,
  getMockLocaleCountryFromCookie,
  getStoredVisitorCountryFromCookie,
  resolveVisitorCountryFromRequest,
}

const VISITOR_COUNTRY_STORAGE_KEY = 'console.visitorCountryCode'
const VISITOR_COUNTRY_EVENT = 'visitorCountryChange'

const MOCK_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365

declare global {
  interface Window {
    __SSR_VISITOR_COUNTRY__?: string | null
  }
}

function cookieSecureSuffix(): string {
  if (typeof window === 'undefined') return ''
  return window.location.protocol === 'https:' ? '; Secure' : ''
}

function writeCookie(name: string, value: string | null, maxAgeSeconds?: number) {
  if (typeof document === 'undefined') return
  const secure = cookieSecureSuffix()
  if (!value) {
    document.cookie = `${name}=; path=/; max-age=0; SameSite=Lax${secure}`
    return
  }
  const maxAge =
    maxAgeSeconds === undefined ? '' : `; max-age=${maxAgeSeconds}`
  document.cookie = `${name}=${encodeURIComponent(value)}; path=/${maxAge}; SameSite=Lax${secure}`
}

export function readSsrVisitorCountryFromWindow(): string | null {
  if (typeof window === 'undefined') return null
  return normalizeCountryCode(window[SSR_VISITOR_COUNTRY_WINDOW_KEY])
}

export function readVisitorCountryCookie(): string | null {
  if (typeof document === 'undefined') return null
  return getStoredVisitorCountryFromCookie(document.cookie)
}

export function readMockLocaleCountryCookie(): string | null {
  if (typeof document === 'undefined') return null
  return getMockLocaleCountryFromCookie(document.cookie)
}

export function syncMockLocaleCountryCookie(
  countryCode: string | null | undefined,
) {
  writeCookie(
    MOCK_LOCALE_COUNTRY_COOKIE_NAME,
    normalizeCountryCode(countryCode),
    MOCK_COOKIE_MAX_AGE_SECONDS,
  )
}

export function readStoredVisitorCountry(): string | null {
  if (typeof window === 'undefined') return null
  try {
    return (
      normalizeCountryCode(
        window.sessionStorage.getItem(VISITOR_COUNTRY_STORAGE_KEY),
      ) ?? readVisitorCountryCookie()
    )
  } catch {
    return readVisitorCountryCookie()
  }
}

export function persistVisitorCountryCode(
  countryCode: string | null | undefined,
) {
  if (typeof window === 'undefined') return
  const normalized = normalizeCountryCode(countryCode)
  try {
    if (!normalized) {
      window.sessionStorage.removeItem(VISITOR_COUNTRY_STORAGE_KEY)
    } else {
      window.sessionStorage.setItem(VISITOR_COUNTRY_STORAGE_KEY, normalized)
    }
  } catch {
    // sessionStorage can be unavailable; cookie still covers SSR return visits.
  }
  writeCookie(VISITOR_COUNTRY_COOKIE_NAME, normalized)
  window.dispatchEvent(new CustomEvent(VISITOR_COUNTRY_EVENT))
}

export function subscribeStoredVisitorCountry(onStoreChange: () => void) {
  if (typeof window === 'undefined') return () => undefined
  window.addEventListener(VISITOR_COUNTRY_EVENT, onStoreChange)
  return () => {
    window.removeEventListener(VISITOR_COUNTRY_EVENT, onStoreChange)
  }
}

export function resolveVisitorCountryFromDocumentRequest(
  request: VisitorCountryRequestLike,
): string | null {
  return resolveVisitorCountryFromRequest(request)
}
