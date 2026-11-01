import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { useVisitorCountryCode } from '@/hooks/use-visitor-country'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'

/**
 * True while a holiday overlay banner is scheduled (UTC, cloud) and not
 * dismissed, or force-previewed.
 */
export function useHolidayBanner(bannerId: string): boolean {
  const { account } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const country = useVisitorCountryCode()
  const banner = getConsoleBannerById(bannerId)

  return (
    !!banner &&
    isConsoleBannerVisible(banner, {
      preview: isPreviewEnabled(bannerId),
      dismissed: isConsoleBannerDismissed(
        (account as Models.User | undefined)?.prefs as UserPrefs | undefined,
        bannerId,
      ),
      country,
    }) &&
    !isScreenshotModeActive
  )
}
