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
  if (options?.dismissed) return false
  if (options?.preview) return true
  if (banner.cloudOnly) {
    if (options?.cloud === false) return false
    if (options?.cloud !== true && !isCloudProfile()) return false
  }
  return isConsoleBannerScheduled(banner, options?.now)
}
