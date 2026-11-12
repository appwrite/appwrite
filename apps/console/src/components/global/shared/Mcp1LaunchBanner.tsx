import { useMemo, useState, type MouseEvent } from 'react'
import { useLocation } from '@tanstack/react-router'
import { ArrowUpRight, X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { Badge } from '@/components/ui/badge'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  isFullscreenWizardPath,
  isGuestConsoleBannerDismissed,
  MCP1_LAUNCH_BANNER_ID,
  persistGuestConsoleBannerDismiss,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import {
  isConsoleBannerDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const MCP1_LAUNCH_BANNER = getConsoleBannerById(MCP1_LAUNCH_BANNER_ID)!
const MCP1_URL =
  'https://mcp1.com/?utm_source=appwrite&utm_medium=banner&utm_campaign=mcp1-launch'

function Mcp1Mark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={className}
      aria-hidden
      focusable="false"
    >
      <rect width="24" height="24" rx="6.72" fill="#ffb3d9" />
      <path fill="#0a0a0a" d="M11.521 19V7.455L12.364 8.107H8.912V5H15.088V19Z" />
    </svg>
  )
}

export function Mcp1LaunchBanner() {
  const t = useT()
  const location = useLocation()
  const { account, isAuthenticated } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)
  const [guestDismissed, setGuestDismissed] = useState(() =>
    isGuestConsoleBannerDismissed(MCP1_LAUNCH_BANNER_ID),
  )

  const prefs = (account as { prefs?: UserPrefs } | null | undefined)?.prefs
  const dismissedFromPrefs = useMemo(
    () => isConsoleBannerDismissed(prefs, MCP1_LAUNCH_BANNER_ID),
    [prefs],
  )

  const visible = isConsoleBannerVisible(MCP1_LAUNCH_BANNER, {
    preview: isPreviewEnabled(MCP1_LAUNCH_BANNER_ID),
    dismissed:
      optimisticDismissed ||
      dismissedFromPrefs ||
      (!isAuthenticated && guestDismissed),
  })

  if (
    !visible ||
    isScreenshotModeActive ||
    isFullscreenWizardPath(location.pathname)
  ) {
    return null
  }

  // Website visitors have no account prefs, so their dismissal stays in the
  // browser.
  const handleDismiss = (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    setOptimisticDismissed(true)
    if (isAuthenticated) {
      dismissBanner.mutate(MCP1_LAUNCH_BANNER_ID)
      return
    }
    persistGuestConsoleBannerDismiss(MCP1_LAUNCH_BANNER_ID)
    setGuestDismissed(true)
  }

  return (
    <div
      className={cn(
        'relative isolate min-h-14 w-full shrink-0 overflow-hidden border-b border-[#2d2d31]',
        'bg-[linear-gradient(to_right,#0d0d10_0%,color-mix(in_srgb,#131316_96%,#ffb3d9)_34%,color-mix(in_srgb,#19191c_92%,#ffb3d9)_100%)]',
      )}
    >
      {/* Padding lives on the link itself so the row is a single flex line
          whose items can shrink; `pe-10` keeps it clear of the dismiss button. */}
      <a
        href={MCP1_URL}
        target="_blank"
        rel="noopener noreferrer"
        {...analyticsAttrs(MCP1_LAUNCH_BANNER.event)}
        aria-label={`${t('MCP1 by Appwrite: one URL for every MCP server')}. ${t('Try MCP1')}`}
        className="group relative z-0 flex min-h-14 w-full cursor-pointer items-center justify-center gap-1.5 ps-3 pe-10 transition-colors hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset sm:gap-3 sm:ps-12 sm:pe-12"
      >
        <Badge className="hidden shrink-0 bg-[#ffb3d9]/15 text-[10px] text-[#ffb3d9] sm:inline-flex">
          {t('Just launched')}
        </Badge>
        <span
          aria-hidden
          className="hidden h-3.5 w-px shrink-0 bg-[#2d2d31] sm:inline"
        />
        <Mcp1Mark className="h-5 w-5 shrink-0" />
        <p className="min-w-0 truncate text-[13px] font-medium text-[#fafafa] sm:text-[14px] md:text-[15px]">
          <span className="sm:hidden">{t('MCP1 by Appwrite is live')}</span>
          <span className="hidden sm:inline">
            {t('MCP1 by Appwrite: one URL for every MCP server')}
          </span>
          <span className="text-[#ffb3d9]">_</span>
        </p>
        <span className="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md bg-[#ffb3d9] px-2 text-[12px] font-medium text-[#0a0a0a] transition-colors group-hover:bg-[#ffb3d9]/90 sm:px-3">
          <span className="hidden sm:inline">{t('Try MCP1')}</span>
          <ArrowUpRight className="h-4 w-4 sm:hidden" aria-hidden />
        </span>
      </a>

      <button
        type="button"
        onClick={handleDismiss}
        onPointerDown={(event) => event.stopPropagation()}
        disabled={dismissBanner.isPending}
        aria-label={t('Dismiss banner')}
        className="absolute end-1 top-1/2 z-20 flex h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-[#a8a8b3] transition-colors hover:bg-white/5 hover:text-[#fafafa] disabled:opacity-50 sm:end-3"
      >
        <X className="h-4 w-4" aria-hidden />
      </button>
    </div>
  )
}
