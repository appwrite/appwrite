import { useMemo } from 'react'
import { useLocation, useParams } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  NATIVE_OAUTH_PROMO_BANNER_ID,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { isNativeOAuthPromoPath } from '@/lib/console-banners/native-oauth-promo-path'
import {
  isConsoleBannerDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const NATIVE_OAUTH_PROMO_BANNER = getConsoleBannerById(
  NATIVE_OAUTH_PROMO_BANNER_ID,
)!

export function useNativeOAuthPromoVisibility(
  optimisticDismissed = false,
): boolean {
  const location = useLocation()
  const { projectId } = useParams({ strict: false })
  const { account } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()

  const dismissedFromPrefs = useMemo(
    () =>
      isConsoleBannerDismissed(
        account?.prefs as UserPrefs | undefined,
        NATIVE_OAUTH_PROMO_BANNER_ID,
      ),
    [account?.prefs],
  )

  const scheduledVisible = isConsoleBannerVisible(NATIVE_OAUTH_PROMO_BANNER, {
    preview: isPreviewEnabled(NATIVE_OAUTH_PROMO_BANNER_ID),
    dismissed: optimisticDismissed || dismissedFromPrefs,
  })

  if (
    !scheduledVisible ||
    isScreenshotModeActive ||
    !projectId ||
    !isNativeOAuthPromoPath(location.pathname)
  ) {
    return false
  }

  return true
}
