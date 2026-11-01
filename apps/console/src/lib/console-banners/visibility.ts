import { isCloudProfile } from '@/lib/console-profiles'
import { normalizeVisitorCountryCode } from '@/lib/visitor-country-shared'
import type { ConsoleBannerDefinition } from './catalog'
import { isConsoleBannerScheduled } from './catalog'

export function isConsoleBannerCountry(
  banner: ConsoleBannerDefinition,
  country?: string | null,
): boolean {
  if (!banner.countries?.length) return true
  const normalized = normalizeVisitorCountryCode(country)
  if (!normalized) return false
  return banner.countries.some((code) => code === normalized)
}

export function isConsoleBannerVisible(
  banner: ConsoleBannerDefinition,
  options?: {
    now?: Date
    dismissed?: boolean
    cloud?: boolean
    preview?: boolean
    country?: string | null
  },
): boolean {
  // Debug preview ignores schedule, cloud, country, and dismiss prefs.
  if (options?.preview) return true
  if (options?.dismissed) return false
  if (banner.cloudOnly) {
    if (options?.cloud === false) return false
    if (options?.cloud !== true && !isCloudProfile()) return false
  }
  if (!isConsoleBannerCountry(banner, options?.country)) return false
  return isConsoleBannerScheduled(banner, options?.now)
}
