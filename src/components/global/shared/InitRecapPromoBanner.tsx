import { useMemo, useState, type MouseEvent } from 'react'
import { Link, useLocation } from '@tanstack/react-router'
import { X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { Badge } from '@/components/ui/badge'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  getConsoleBannerById,
  INIT_RECAP_PROMO_BANNER_ID,
  isConsoleBannerVisible,
  isInitRecapPromoPath,
  POSTGRES_PROMO_BANNER_ID,
  PRODUCT_HUNT_BANNER_ID,
  shouldHideInitRecapForHeaderPromo,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import {
  isConsoleBannerDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const INIT_RECAP_PROMO_BANNER = getConsoleBannerById(INIT_RECAP_PROMO_BANNER_ID)!
const POSTGRES_PROMO_BANNER = getConsoleBannerById(POSTGRES_PROMO_BANNER_ID)!
const PRODUCT_HUNT_BANNER = getConsoleBannerById(PRODUCT_HUNT_BANNER_ID)!

export function InitRecapPromoBanner() {
  const t = useT()
  const location = useLocation()
  const { account, isAuthenticated } = useAuth()
  const { features } = useConsoleProfile()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)

  const prefs = account?.prefs as UserPrefs | undefined
  const dismissedFromPrefs = useMemo(
    () => isConsoleBannerDismissed(prefs, INIT_RECAP_PROMO_BANNER_ID),
    [prefs],
  )

  const preview = isPreviewEnabled(INIT_RECAP_PROMO_BANNER_ID)
  const dismissed = optimisticDismissed || dismissedFromPrefs
  const onPromoPath = isInitRecapPromoPath(location.pathname)
  const visible = isConsoleBannerVisible(INIT_RECAP_PROMO_BANNER, {
    preview,
    dismissed,
  })
  const postgresHeaderVisible = isConsoleBannerVisible(POSTGRES_PROMO_BANNER, {
    preview: isPreviewEnabled(POSTGRES_PROMO_BANNER_ID),
    dismissed: isConsoleBannerDismissed(prefs, POSTGRES_PROMO_BANNER_ID),
  })
  const productHuntHeaderVisible = isConsoleBannerVisible(PRODUCT_HUNT_BANNER, {
    preview: isPreviewEnabled(PRODUCT_HUNT_BANNER_ID),
    dismissed: isConsoleBannerDismissed(prefs, PRODUCT_HUNT_BANNER_ID),
  })
  const headerPromoVisible = postgresHeaderVisible || productHuntHeaderVisible

  if (!visible) return null
  if (
    location.pathname === '/init' ||
    location.pathname.startsWith('/init/')
  ) {
    return null
  }
  if (!preview) {
    if (!features.init || !isAuthenticated || !onPromoPath) return null
  }
  if (shouldHideInitRecapForHeaderPromo(location.pathname, headerPromoVisible)) {
    return null
  }

  const persistDismiss = () => {
    setOptimisticDismissed(true)
    if (isAuthenticated) {
      dismissBanner.mutate(INIT_RECAP_PROMO_BANNER_ID)
    }
  }

  const handleDismiss = (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    persistDismiss()
  }

  return (
    <div
      className={cn(
        'fixed bottom-4 start-4 z-50 w-[min(calc(100%-2rem),280px)] overflow-hidden rounded-lg border border-[#2d2d31]',
        'bg-[linear-gradient(to_bottom,#0d0d10_0%,#131316_55%,#19191c_100%)]',
      )}
    >
      <div className="relative flex h-[180px] items-center justify-center overflow-hidden">
        <InitWordmark className="text-[48px] text-[#fafafa]" />
        <Badge className="absolute start-3 top-3 z-10 bg-[#fd366e]/15 text-[10px] text-[#fd366e]">
          {t('Recap')}
        </Badge>
        <button
          type="button"
          onClick={handleDismiss}
          onPointerDown={(event) => event.stopPropagation()}
          disabled={dismissBanner.isPending}
          aria-label={t('Dismiss banner')}
          className="absolute end-2 top-2 z-20 flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-[#a8a8b3] transition-colors hover:bg-white/5 hover:text-[#fafafa] disabled:opacity-50"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>

      <Link
        to="/init"
        {...analyticsAttrs(INIT_RECAP_PROMO_BANNER.event)}
        aria-label={`${t('Catch up on Init')}. ${t('View recap')}`}
        onClick={persistDismiss}
        className="group block p-4 pt-3 transition-colors hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
      >
        <h4 className="text-[15px] font-semibold text-[#fafafa]">
          {t('Catch up on Init')}
          <span className="text-[#fd366e]">_</span>
        </h4>
        <p className="mt-1 text-[13px] leading-relaxed text-[#a8a8b3]">
          {t('Rewatch sessions and explore every launch from Init week.')}
        </p>
        <span className="mt-3 inline-flex h-9 w-full items-center justify-center rounded-md bg-[#fd366e] text-[13px] font-medium text-white transition-colors group-hover:bg-[#fd366e]/90">
          {t('View recap')}
        </span>
      </Link>
    </div>
  )
}
