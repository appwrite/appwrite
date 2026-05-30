import { useRef } from 'react'
import { Link } from '@tanstack/react-router'
import { InitHeroBackground } from '@/components/pages/init/_components/InitHeroBackground'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { Badge } from '@/components/ui/badge'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  getInitOrgPromoBannerContent,
  type InitOrgPromoPhase,
} from '@/lib/init/org-promo-banner'
import type { LaunchEventCta } from '@/lib/init/types'
import type { ReactNode } from 'react'

const BADGE_VARIANT: Record<
  InitOrgPromoPhase,
  'info' | 'success' | 'warning'
> = {
  before: 'info',
  during: 'success',
  after: 'info',
}

function InitOrgPromoBannerLink({
  cta,
  className,
  children,
}: {
  cta: LaunchEventCta
  className?: string
  children: ReactNode
}) {
  if (cta.to) {
    return (
      <Link
        to={cta.to}
        search={cta.redirect ? { redirect: cta.redirect } : undefined}
        className={className}
      >
        {children}
      </Link>
    )
  }

  if (cta.href) {
    return (
      <a
        href={cta.href}
        target={cta.external !== false ? '_blank' : undefined}
        rel={cta.external !== false ? 'noopener noreferrer' : undefined}
        className={className}
      >
        {children}
      </a>
    )
  }

  return <div className={className}>{children}</div>
}

export function InitOrgPromoBanner() {
  const bannerRef = useRef<HTMLDivElement>(null)
  const { mockInitCurrentDay } = useDebugOverrides()
  const content = getInitOrgPromoBannerContent({
    mockCurrentDay: mockInitCurrentDay,
  })

  if (!content) return null

  return (
    <div className="overflow-visible border-b border-border">
      <div
        ref={bannerRef}
        className="group/banner relative min-h-14 overflow-visible bg-background [clip-path:inset(0_-100vw_0_-100vw)]"
      >
        <InitHeroBackground
          containerRef={bannerRef}
          compact
          fullWidthMotion
          active
        />

        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 z-[1] bg-muted/0 transition-colors duration-300 ease-out group-hover/banner:bg-muted/30"
        />

        <InitOrgPromoBannerLink
          cta={content.cta}
          className="relative z-10 mx-auto flex min-h-14 w-full max-w-7xl cursor-pointer items-center justify-center gap-2 px-4 py-3 sm:gap-2.5 sm:px-6"
        >
          <InitWordmark className="shrink-0 text-[20px] text-foreground transition-colors duration-300 ease-out group-hover/banner:text-foreground sm:text-[22px]" />
          <span
            aria-hidden
            className="hidden shrink-0 text-muted-foreground/40 sm:inline"
          >
            ·
          </span>
          <span className="hidden shrink-0 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground md:inline">
            {content.dateRangeLabel}
          </span>
          {content.badgeLabel ? (
            <>
              <span
                aria-hidden
                className="hidden shrink-0 text-muted-foreground/40 md:inline"
              >
                ·
              </span>
              <Badge
                variant={BADGE_VARIANT[content.phase]}
                className="text-[10px] shrink-0"
              >
                {content.badgeLabel}
              </Badge>
            </>
          ) : null}
          <span
            aria-hidden
            className="hidden shrink-0 text-muted-foreground/40 sm:inline"
          >
            ·
          </span>
          <p className="min-w-0 truncate text-[13px] text-muted-foreground transition-colors duration-300 ease-out group-hover/banner:text-foreground/80">
            {content.message}
          </p>
        </InitOrgPromoBannerLink>
      </div>
    </div>
  )
}
