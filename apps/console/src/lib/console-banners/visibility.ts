import { isCloudProfile } from '@/lib/console-profiles'
import type { ConsoleBannerDefinition } from './catalog'
import { isConsoleBannerScheduled } from './catalog'

export function isConsoleBannerVisible(
  banner: ConsoleBannerDefinition,
  options?: {
    now?: Date
    dismissed?: boolean
    cloud?: boolean
    preview?: boolean
  },
): boolean {
  // Debug preview ignores schedule, cloud, and dismiss prefs.
  if (options?.preview) return true
  if (options?.dismissed) return false
  if (banner.cloudOnly) {
    if (options?.cloud === false) return false
    if (options?.cloud !== true && !isCloudProfile()) return false
  }
  return isConsoleBannerScheduled(banner, options?.now)
}
