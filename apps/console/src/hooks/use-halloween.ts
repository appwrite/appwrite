import { HALLOWEEN_BANNER_ID } from '@/lib/console-banners'
import { useHolidayBanner } from '@/hooks/use-holiday-banner'

/** True while the Halloween banner is scheduled (UTC, cloud) or force-previewed. */
export function useHalloween(): boolean {
  return useHolidayBanner(HALLOWEEN_BANNER_ID)
}
