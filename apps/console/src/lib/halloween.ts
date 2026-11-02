import { normalizeVisitorCountryCode } from '@/lib/visitor-country-shared'

/** ISO 3166-1 alpha-2 countries where Halloween is widely celebrated. */
export const HALLOWEEN_COUNTRIES: ReadonlySet<string> = new Set([
  'US',
  'CA',
  'MX',
  'PR',
  'IE',
  'GB',
  'IM',
  'JE',
  'GG',
  'AU',
  'NZ',
  'DE',
  'NL',
  'BE',
  'JP',
  'KR',
  'PH',
])

const OCTOBER = 9
const HALLOWEEN_DAY = 31

/** Days the decorations show, ending on Halloween itself (local calendar). */
export const HALLOWEEN_SEASON_DAYS = 7

export function isHalloweenSeason(date: Date): boolean {
  if (date.getMonth() !== OCTOBER) return false
  return date.getDate() > HALLOWEEN_DAY - HALLOWEEN_SEASON_DAYS
}

export function isHalloweenCountry(
  countryCode: string | null | undefined,
): boolean {
  const normalized = normalizeVisitorCountryCode(countryCode)
  return normalized !== null && HALLOWEEN_COUNTRIES.has(normalized)
}

export function isHalloween(
  date: Date,
  countryCode: string | null | undefined,
): boolean {
  return isHalloweenSeason(date) && isHalloweenCountry(countryCode)
}
