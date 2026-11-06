import { useState, type MouseEvent } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  getConsoleBannerById,
  NATIVE_OAUTH_PROMO_BANNER_ID,
  NATIVE_OAUTH_PROMO_CARD_SIZE_PX,
  NATIVE_OAUTH_PROMO_LEARN_MORE_PATH,
  useNativeOAuthPromoVisibility,
} from '@/lib/console-banners'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'

import { NativeOAuthPromoCover } from '@/components/global/shared/NativeOAuthPromoCover'

const NATIVE_OAUTH_PROMO_BANNER = getConsoleBannerById(
  NATIVE_OAUTH_PROMO_BANNER_ID,
)!

export function NativeOAuthPromoBanner() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const dismissBanner = useDismissConsoleBanner()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)

  const visible = useNativeOAuthPromoVisibility(optimisticDismissed)

  if (!visible || !projectId) return null

  const handleDismiss = (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    setOptimisticDismissed(true)
    dismissBanner.mutate(NATIVE_OAUTH_PROMO_BANNER_ID)
  }

  const sizePx = NATIVE_OAUTH_PROMO_CARD_SIZE_PX
  const squareSize = `min(${sizePx}px, calc(100vw - 2rem), calc(100dvh - 2rem))`

  return (
    <div
      className={cn(
        'fixed bottom-4 start-4 z-50 flex shrink-0 flex-col overflow-hidden rounded-lg border border-[#2d2d31]',
        'bg-[linear-gradient(to_bottom,#0d0d10_0%,#131316_55%,#19191c_100%)]',
      )}
      style={{
        width: squareSize,
        height: squareSize,
      }}
    >
      <div className="relative min-h-0 flex-1 overflow-hidden rounded-t-lg bg-[linear-gradient(to_bottom,#131316_0%,#19191c_52%,#0d0d10_100%)]">
        <NativeOAuthPromoCover layout="square" />
        <Badge className="absolute start-3 top-3 z-10 bg-[#fd366e]/15 text-[10px] text-[#fd366e]">
          {t('New')}
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

      <div className="shrink-0 px-4 pb-4 pt-3">
        <h4 className="text-[15px] font-semibold text-[#fafafa]">
          {t('Add native OAuth to your app')}
          <span className="text-[#fd366e]">_</span>
        </h4>
        <p className="mt-2 text-[13px] leading-relaxed text-[#a8a8b3]">
          {t(
            'Ship native sign-in dialogs for Apple, Google, and more. Your users never leave the app, and you never ship client secrets.',
          )}
        </p>
        <div className="mt-3 flex flex-row gap-2">
          <Link
            to="/projects/$projectId/auth/social-providers"
            params={{ projectId }}
            {...analyticsAttrs(NATIVE_OAUTH_PROMO_BANNER.event)}
            className="inline-flex h-9 min-w-0 flex-1 items-center justify-center rounded-md bg-[#fd366e] px-3 text-[13px] font-medium text-white transition-colors hover:bg-[#fd366e]/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
          >
            {t('Configure OAuth')}
          </Link>
          <Link
            to={NATIVE_OAUTH_PROMO_LEARN_MORE_PATH}
            {...analyticsAttrs('native-oauth-promo-banner-learn-more')}
            className="inline-flex h-9 min-w-0 flex-1 items-center justify-center rounded-md border border-[#3f4346] bg-transparent px-3 text-[13px] font-medium text-[#fafafa] transition-colors hover:bg-white/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
          >
            {t('Read announcement')}
          </Link>
        </div>
      </div>
    </div>
  )
}
