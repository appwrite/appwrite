import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { useVisitorCountryCode } from '@/hooks/use-visitor-country'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'

/** True while a holiday overlay banner is scheduled (UTC, cloud) or force-previewed. */
export function useHolidayBanner(bannerId: string): boolean {
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const country = useVisitorCountryCode()
  const banner = getConsoleBannerById(bannerId)

  return (
    !!banner &&
    isConsoleBannerVisible(banner, {
      preview: isPreviewEnabled(bannerId),
      country,
    }) &&
    !isScreenshotModeActive
  )
}
