import {
  COOKIE_CONSENT_STORAGE_KEY,
  COOKIE_CONSENT_VERSION,
} from '@/lib/cookie-consent/constants'
import type { StoredCookieConsent } from '@/lib/cookie-consent/types'

export function readStoredCookieConsent(): StoredCookieConsent | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = localStorage.getItem(COOKIE_CONSENT_STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as StoredCookieConsent
    if (
      parsed == null ||
      typeof parsed !== 'object' ||
      parsed.version !== COOKIE_CONSENT_VERSION ||
      typeof parsed.analytics !== 'boolean' ||
      typeof parsed.updatedAt !== 'string'
    ) {
      return null
    }
    return parsed
  } catch {
    return null
  }
}

export function writeStoredCookieConsent(analytics: boolean): StoredCookieConsent {
  const value: StoredCookieConsent = {
    version: COOKIE_CONSENT_VERSION,
    analytics,
    updatedAt: new Date().toISOString(),
  }
  if (typeof window !== 'undefined') {
    try {
      localStorage.setItem(COOKIE_CONSENT_STORAGE_KEY, JSON.stringify(value))
    } catch {
      /* private mode */
    }
  }
  return value
}
