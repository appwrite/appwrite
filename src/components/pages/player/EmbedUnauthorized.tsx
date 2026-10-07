import { Link, useRouterState } from '@tanstack/react-router'
import { AppwriteMarkIcon } from '@/components/global/shared/AppwriteMarkIcon'
import { Button } from '@/components/ui/button'
import { VIDEO_EMBED_UNAUTHORIZED } from '@/lib/videos/embed-player-errors'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Full-bleed private-video state for the embed player (HTTP 401). */
export function EmbedUnauthorized({ className }: { className?: string }) {
  const t = useT()
  const redirect = useRouterState({
    select: (state) =>
      `${state.location.pathname}${state.location.searchStr}`,
  })

  return (
    <div
      className={cn(
        'flex h-[100dvh] w-full items-center justify-center bg-black px-6',
        className,
      )}
    >
      <div className="flex max-w-sm flex-col items-center text-center">
        <AppwriteMarkIcon className="size-8 text-[#fd366e]" />
        <h1 className="mt-5 text-[17px] font-semibold text-white">
          {t(VIDEO_EMBED_UNAUTHORIZED.title)}
        </h1>
        <p className="mt-2 text-[14px] leading-relaxed text-white/70">
          {t(VIDEO_EMBED_UNAUTHORIZED.message)}
        </p>
        <Button variant="brandCta" className="mt-6 h-9 px-4 text-[13px]" asChild>
          <Link to="/sign-in" search={{ redirect }}>
            {t('Sign in')}
          </Link>
        </Button>
      </div>
    </div>
  )
}
