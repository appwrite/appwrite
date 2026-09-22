import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type MouseEvent,
} from 'react'
import { Link, useLocation } from '@tanstack/react-router'
import { X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  isFullscreenWizardPath,
  isGuestConsoleBannerDismissed,
  persistGuestConsoleBannerDismiss,
  START_PROMO_BANNER_ID,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { useStartPromoBannerAudience } from '@/lib/console-banners/use-start-promo-banner-audience'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import {
  isConsoleBannerDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const START_PROMO_BANNER = getConsoleBannerById(START_PROMO_BANNER_ID)!

const START_PROMO_TAJ_SRC = '/images/console/banners/start-promo-taj.svg'

const START_PROMO_IMAGE_MASK_STYLE = {
  WebkitMaskImage:
    'linear-gradient(to right, #000 30%, transparent 72%), linear-gradient(to bottom, #000 62%, transparent 86%)',
  WebkitMaskComposite: 'source-in',
  maskImage:
    'linear-gradient(to right, #000 30%, transparent 72%), linear-gradient(to bottom, #000 62%, transparent 86%)',
  maskComposite: 'intersect',
} as const

const BLOG_POST_PATH = '/blog/post/introducing-appwrite-cloud-start-plan'

/** After a completed entrance, remounts show the banner without animating. */
let startPromoBannerSkipEntrance = false

const START_PROMO_BANNER_ENTRANCE_MS = 300
/** Ignore brief eligible flashes while async gates settle. */
const START_PROMO_BANNER_STABLE_MS = 50

export function StartPromoBanner() {
  const t = useT()
  const location = useLocation()
  const { account, isAuthenticated } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const audience = useStartPromoBannerAudience()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)
  const [guestDismissed, setGuestDismissed] = useState(() =>
    isGuestConsoleBannerDismissed(START_PROMO_BANNER_ID),
  )
  const [mountExpanded, setMountExpanded] = useState(
    () => startPromoBannerSkipEntrance,
  )
  const [stableShow, setStableShow] = useState(
    () => startPromoBannerSkipEntrance,
  )
  const showBannerRef = useRef(false)

  const preview = isPreviewEnabled(START_PROMO_BANNER_ID)

  const dismissedFromPrefs = useMemo(
    () =>
      isConsoleBannerDismissed(
        account?.prefs as UserPrefs | undefined,
        START_PROMO_BANNER_ID,
      ),
    [account?.prefs],
  )

  const dismissed =
    optimisticDismissed ||
    dismissedFromPrefs ||
    (!isAuthenticated && guestDismissed)

  const scheduledVisible = isConsoleBannerVisible(START_PROMO_BANNER, {
    preview,
    dismissed,
  })

  const showBanner =
    scheduledVisible &&
    !isScreenshotModeActive &&
    !isFullscreenWizardPath(location.pathname) &&
    (preview || audience.status === 'eligible')

  showBannerRef.current = showBanner

  const renderBanner = showBanner && stableShow

  const showExpanded =
    renderBanner && (startPromoBannerSkipEntrance || mountExpanded)

  const animateEntrance = renderBanner && !startPromoBannerSkipEntrance

  useEffect(() => {
    if (!showBanner) {
      setStableShow(false)
      setMountExpanded(false)
      return
    }
    if (startPromoBannerSkipEntrance) {
      setStableShow(true)
      setMountExpanded(true)
      return
    }
    const stableTimer = window.setTimeout(() => {
      if (!showBannerRef.current) return
      setStableShow(true)
    }, START_PROMO_BANNER_STABLE_MS)
    return () => clearTimeout(stableTimer)
  }, [showBanner])

  useEffect(() => {
    if (!renderBanner) {
      setMountExpanded(false)
      return
    }
    if (startPromoBannerSkipEntrance) {
      setMountExpanded(true)
      return
    }
    const frame = requestAnimationFrame(() => {
      if (!showBannerRef.current) return
      setMountExpanded(true)
    })
    return () => cancelAnimationFrame(frame)
  }, [renderBanner])

  useEffect(() => {
    if (!renderBanner || !mountExpanded || startPromoBannerSkipEntrance) {
      return
    }
    const timer = window.setTimeout(() => {
      if (!showBannerRef.current) return
      startPromoBannerSkipEntrance = true
    }, START_PROMO_BANNER_ENTRANCE_MS)
    return () => clearTimeout(timer)
  }, [renderBanner, mountExpanded])

  if (!renderBanner) {
    return null
  }

  const handleDismiss = (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    setOptimisticDismissed(true)
    if (isAuthenticated) {
      dismissBanner.mutate(START_PROMO_BANNER_ID)
      return
    }
    persistGuestConsoleBannerDismiss(START_PROMO_BANNER_ID)
    setGuestDismissed(true)
  }

  const headline = t('Namaste India')
  const subline = t('Appwrite Start, a plan built for Indian developers.')
  const ctaLabel = t('Learn more')

  return (
    <div
      className={cn(
        'grid w-full shrink-0',
        animateEntrance &&
          'transition-[grid-template-rows] duration-300 ease-out motion-reduce:transition-none',
        showExpanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
      )}
    >
      <div className="min-h-0 overflow-hidden">
        <div
          className={cn(
            'relative isolate w-full overflow-hidden border-b border-[#2d2d31]',
            'bg-[linear-gradient(to_right,#0d0d10_0%,#131316_34%,#19191c_62%)]',
            animateEntrance &&
              'transition-opacity duration-300 ease-out motion-reduce:transition-none',
            showExpanded ? 'opacity-100' : 'opacity-0',
          )}
        >
          <Link
            to={BLOG_POST_PATH}
            {...analyticsAttrs(START_PROMO_BANNER.event)}
            aria-label={`${headline}. ${subline} ${ctaLabel}`}
            className="group relative z-0 flex min-h-28 w-full cursor-pointer items-center justify-center px-3 pe-10 transition-colors hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset sm:gap-7 sm:px-9 sm:pe-12"
          >
            <div
              aria-hidden
              className="pointer-events-none absolute inset-y-0 start-0 hidden w-[min(42%,360px)] overflow-hidden sm:block"
            >
              <img
                src={START_PROMO_TAJ_SRC}
                alt=""
                className="absolute start-[-40px] top-[-92px] h-[360px] w-auto max-w-none opacity-90"
                style={START_PROMO_IMAGE_MASK_STYLE}
              />
            </div>

            <div className="relative z-10 flex min-w-0 flex-col items-center gap-1 text-center sm:items-start sm:text-start">
              <p className="truncate text-[18px] font-medium leading-tight tracking-[-0.02em] text-[#fafafa] sm:text-[22px] md:text-[26px]">
                {headline}
                <span className="text-[#fd366e]">_</span>
              </p>
              <p className="hidden max-w-[min(100%,520px)] truncate text-[13px] text-[#c9c9d1] sm:block sm:text-[15px]">
                {subline}
              </p>
            </div>

            <span
              aria-hidden
              className="relative z-10 mx-2 hidden h-11 w-px shrink-0 bg-[#2d2d31] md:inline"
            />

            <div className="relative z-10 hidden shrink-0 items-center gap-5 md:flex">
              <span className="inline-flex h-9 items-center justify-center rounded-lg bg-[#fd366e] px-[18px] text-[14px] font-normal text-white transition-colors group-hover:bg-[#fd366e]/90">
                {ctaLabel}
              </span>
              <div className="flex items-baseline gap-1.5 whitespace-nowrap text-[#fafafa]">
                <span className="text-[24px] font-medium tracking-[-0.02em]">
                  $10
                </span>
                <span className="text-[14px] text-[#a8a8b3]">{t('/month')}</span>
              </div>
            </div>

            <span className="relative z-10 inline-flex h-8 shrink-0 items-center justify-center rounded-lg bg-[#fd366e] px-3 text-[13px] font-medium text-white transition-colors group-hover:bg-[#fd366e]/90 md:hidden">
              {ctaLabel}
            </span>
          </Link>

          <button
            type="button"
            onClick={handleDismiss}
            onPointerDown={(event) => event.stopPropagation()}
            disabled={dismissBanner.isPending}
            aria-label={t('Dismiss banner')}
            className="absolute end-1 top-2 z-20 flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-[#a8a8b3] transition-colors hover:bg-white/5 hover:text-[#fafafa] disabled:opacity-50 sm:end-3"
          >
            <X className="h-4 w-4" aria-hidden />
          </button>
        </div>
      </div>
    </div>
  )
}
