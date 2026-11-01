import { FOURTH_OF_JULY_BANNER_ID } from '@/lib/console-banners'
import { useHolidayBanner } from '@/hooks/use-holiday-banner'

/** True while the Fourth of July banner is scheduled (UTC, cloud) or force-previewed. */
export function useFourthOfJuly(): boolean {
  return useHolidayBanner(FOURTH_OF_JULY_BANNER_ID)
}
