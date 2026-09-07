import { useMemo, useState, type MouseEvent } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Badge } from '@/components/ui/badge'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  getConsoleBannerById,
  isConsoleBannerVisible,
  POSTGRES_PROMO_BANNER_ID,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { CREATE_DATABASE_WIZARD_POSTGRES_SEARCH } from '@/lib/databases/create-database-wizard-search'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import {
  isConsoleBannerDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

const POSTGRES_PROMO_BANNER = getConsoleBannerById(POSTGRES_PROMO_BANNER_ID)!

const POSTGRES_PROMO_ELEPHANT_SRC =
  '/images/console/banners/postgres-promo-elephant.avif'

const POSTGRES_PROMO_IMAGE_MASK_STYLE = {
  WebkitMaskImage:
    'linear-gradient(to right, #000 60%, transparent 92%), linear-gradient(to bottom, #000 58%, transparent 68%)',
  WebkitMaskComposite: 'source-in',
  maskImage:
    'linear-gradient(to right, #000 60%, transparent 92%), linear-gradient(to bottom, #000 58%, transparent 68%)',
  maskComposite: 'intersect',
} as const

export function PostgresPromoBanner() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { account } = useAuth()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const dismissBanner = useDismissConsoleBanner()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)

  const dismissedFromPrefs = useMemo(
    () =>
      isConsoleBannerDismissed(
        account?.prefs as UserPrefs | undefined,
        POSTGRES_PROMO_BANNER_ID,
      ),
    [account?.prefs],
  )

  const visible = isConsoleBannerVisible(POSTGRES_PROMO_BANNER, {
    preview: isPreviewEnabled(POSTGRES_PROMO_BANNER_ID),
    dismissed: optimisticDismissed || dismissedFromPrefs,
  })

  if (!visible) return null

  const handleDismiss = (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    setOptimisticDismissed(true)
    dismissBanner.mutate(POSTGRES_PROMO_BANNER_ID)
  }

  return (
    <div
      className={cn(
        'relative isolate min-h-14 w-full shrink-0 overflow-hidden border-b border-[#2d2d31]',
        'bg-[linear-gradient(to_right,#0d0d10_0%,#131316_34%,#19191c_62%)]',
      )}
    >
      <Link
        to="/projects/$projectId/databases/create"
        params={{ projectId: projectId! }}
        search={CREATE_DATABASE_WIZARD_POSTGRES_SEARCH}
        {...analyticsAttrs('postgres-promo-banner-try-now')}
        aria-label={`${t('Appwrite now speaks PostgreSQL')}. ${t('Setup')}`}
        className="group relative z-0 flex min-h-14 w-full cursor-pointer items-center justify-center transition-colors hover:bg-white/[0.03] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
      >
        <div
          aria-hidden
          className="pointer-events-none absolute inset-y-0 start-0 hidden w-[min(38%,320px)] overflow-hidden sm:block"
        >
          <img
            src={POSTGRES_PROMO_ELEPHANT_SRC}
            alt=""
            className="absolute start-[-5px] bottom-[-48px] h-[120px] w-auto max-w-none"
            style={POSTGRES_PROMO_IMAGE_MASK_STYLE}
          />
        </div>

        <div className="relative z-10 flex min-h-14 items-center justify-center gap-1.5 px-10 pe-10 sm:gap-3 sm:pe-12">
          <Badge className="hidden shrink-0 bg-[#fd366e]/15 text-[10px] text-[#fd366e] sm:inline-flex">
            {t('New')}
          </Badge>
          <span
            aria-hidden
            className="hidden h-3.5 w-px shrink-0 bg-[#2d2d31] sm:inline"
          />
          <p className="min-w-0 truncate text-[13px] font-medium text-[#fafafa] sm:text-[14px] md:text-[15px]">
            {t('Appwrite now speaks PostgreSQL')}
            <span className="text-[#fd366e]">_</span>
          </p>
          <span className="inline-flex h-7 shrink-0 items-center justify-center rounded-md bg-[#fd366e] px-3 text-[12px] font-medium text-white transition-colors group-hover:bg-[#fd366e]/90">
            {t('Setup')}
          </span>
        </div>
      </Link>

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
