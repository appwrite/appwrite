import { CHRISTMAS_BANNER_ID } from '@/lib/console-banners'
import { useHolidayBanner } from '@/hooks/use-holiday-banner'

/** True while the Christmas banner is scheduled (UTC, cloud) or force-previewed. */
export function useChristmas(): boolean {
  return useHolidayBanner(CHRISTMAS_BANNER_ID)
}
