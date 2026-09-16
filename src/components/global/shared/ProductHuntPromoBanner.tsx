import { useMemo, useState, type MouseEvent } from 'react'
import { useLocation } from '@tanstack/react-router'
import { X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Badge } from '@/components/ui/badge'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  isFullscreenWizardPath,
  PRODUCT_HUNT_BANNER_ID,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import {
  isConsoleBannerDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const PRODUCT_HUNT_BANNER = getConsoleBannerById(PRODUCT_HUNT_BANNER_ID)!
const PRODUCT_HUNT_URL = 'https://www.producthunt.com/products/appwrite/launches/appwrite-2-0'

function ProductHuntMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="207.3 63.07 685.39 685.39"
      className={className}
      aria-hidden
      focusable="false"
    >
      <path
        fill="#DA552F"
        d="M892.694,405.769c0,189.264-153.43,342.694-342.694,342.694c-189.264,0-342.695-153.431-342.695-342.694c0-189.265,153.432-342.694,342.695-342.694C739.265,63.074,892.694,216.504,892.694,405.769"
      />
      <path
        fill="#FFFFFF"
        d="M595.693,234.422H430.057v342.693h68.539V474.309h97.098c66.241,0,119.942-53.701,119.942-119.943C715.636,288.122,661.935,234.422,595.693,234.422 M595.693,405.768l-97.098,0.001V302.961h97.098c28.389,0,51.402,23.013,51.402,51.402S624.082,405.768,595.693,405.768"
      />
    </svg>
  )
}

export function ProductHuntPromoBanner() {
  const t = useT()
  const location = useLocation()
  const { account, isAuthenticated } = useAuth()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)

  const dismissedFromPrefs = useMemo(
    () =>
      isConsoleBannerDismissed(
        account?.prefs as UserPrefs | undefined,
        PRODUCT_HUNT_BANNER_ID,
      ),
    [account?.prefs],
  )

  const visible = isConsoleBannerVisible(PRODUCT_HUNT_BANNER, {
    preview: isPreviewEnabled(PRODUCT_HUNT_BANNER_ID),
    dismissed: optimisticDismissed || dismissedFromPrefs,
  })

  if (!visible || isFullscreenWizardPath(location.pathname)) {
    return null
  }

  const persistDismiss = () => {
    setOptimisticDismissed(true)
    if (isAuthenticated) {
      dismissBanner.mutate(PRODUCT_HUNT_BANNER_ID)
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
        'relative isolate min-h-14 w-full shrink-0 overflow-hidden border-b border-[#2d2d31]',
        'bg-[linear-gradient(to_right,#0d0d10_0%,color-mix(in_srgb,#131316_97%,#FE9567)_34%,color-mix(in_srgb,#19191c_94%,#FE9567)_100%)]',
      )}
    >
      <a
        href={PRODUCT_HUNT_URL}
        target="_blank"
        rel="noopener noreferrer"
        {...analyticsAttrs(PRODUCT_HUNT_BANNER.event)}
        aria-label={`${t('Appwrite 2.0 is launching on Product Hunt today')}. ${t('Share your take')}`}
        className="group relative z-0 flex min-h-14 w-full cursor-pointer items-center justify-center transition-colors hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
      >
        <div className="relative z-10 flex min-h-14 items-center justify-center gap-1.5 px-10 pe-10 sm:gap-3 sm:pe-12">
          <Badge className="hidden shrink-0 bg-[#fd366e]/15 text-[10px] text-[#fd366e] sm:inline-flex">
            {t('Now')}
          </Badge>
          <span
            aria-hidden
            className="hidden h-3.5 w-px shrink-0 bg-[#2d2d31] sm:inline"
          />
          <ProductHuntMark className="h-5 w-5 shrink-0" />
          <p className="min-w-0 truncate text-[13px] font-medium text-[#fafafa] sm:text-[14px] md:text-[15px]">
            {t('Appwrite 2.0 is launching on Product Hunt today')}
            <span className="text-[#fd366e]">_</span>
          </p>
          <span className="inline-flex h-7 shrink-0 items-center justify-center rounded-md bg-[#fd366e] px-3 text-[12px] font-medium text-white transition-colors group-hover:bg-[#fd366e]/90">
            {t('Share your take')}
          </span>
        </div>
      </a>

      <button
        type="button"
        onClick={handleDismiss}
        onPointerDown={(event) => event.stopPropagation()}
        disabled={dismissBanner.isPending}
        aria-label={t('Dismiss banner')}
        className="absolute end-2 top-1/2 z-20 flex h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-[#a8a8b3] transition-colors hover:bg-white/5 hover:text-[#fafafa] disabled:opacity-50 sm:end-3"
      >
        <X className="h-4 w-4" aria-hidden />
      </button>
    </div>
  )
}
