/** @deprecated Import from `@/lib/console-banners` instead. */
export {
  POSTGRES_PROMO_BANNER_ID,
  POSTGRES_PROMO_BANNER_START_MS,
  POSTGRES_PROMO_BANNER_END_MS,
  getConsoleBannerById,
  isConsoleBannerScheduled,
} from '@/lib/console-banners/catalog'
export { isConsoleBannerVisible as isPostgresPromoBannerVisible } from '@/lib/console-banners/visibility'
import {
  getConsoleBannerById,
  POSTGRES_PROMO_BANNER_ID,
} from '@/lib/console-banners/catalog'
import { isConsoleBannerScheduled } from '@/lib/console-banners/catalog'
import { isConsoleBannerVisible } from '@/lib/console-banners/visibility'

const postgresBanner = getConsoleBannerById(POSTGRES_PROMO_BANNER_ID)!

export function isPostgresPromoBannerVisible(options?: {
  now?: Date
  dismissed?: boolean
  cloud?: boolean
  preview?: boolean
}): boolean {
  return isConsoleBannerVisible(postgresBanner, options)
}

/** @deprecated Use `isConsoleBannerScheduled(postgresBanner, now)` instead. */
export function isPostgresPromoBannerScheduled(now: Date = new Date()): boolean {
  return isConsoleBannerScheduled(postgresBanner, now)
}
