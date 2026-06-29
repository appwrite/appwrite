import type { Models } from '@appwrite.io/console'

/**
 * GDPR-aligned regions beyond EU membership (Appwrite `locale.eu` is EU-only).
 * UK GDPR and EEA members Iceland, Liechtenstein, and Norway.
 */
const GDPR_ALIGNED_COUNTRY_CODES = new Set(['GB', 'IS', 'LI', 'NO'])

/**
 * Whether the visitor is in a region where non-essential cookies require consent.
 * Uses Appwrite Console locale (`sdk.forConsole.locale.get()`), primarily `eu`.
 */
export function requiresCookieConsentBanner(
  locale: Models.Locale | null | undefined,
): boolean {
  if (!locale?.countryCode) return false
  if (locale.eu) return true
  return GDPR_ALIGNED_COUNTRY_CODES.has(locale.countryCode.trim().toUpperCase())
}
