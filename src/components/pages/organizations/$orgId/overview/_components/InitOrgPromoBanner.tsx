import { useRef } from 'react'
import { Link } from '@tanstack/react-router'
import { InitHeroBackground } from '@/components/pages/init/_components/InitHeroBackground'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  getInitOrgPromoBannerContent,
  type InitOrgPromoPhase,
} from '@/lib/init/org-promo-banner'
import type { LaunchEventCta } from '@/lib/init/types'
import { cn } from '@/lib/utils'
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
    <div className="border-b border-border">
      <div
        ref={bannerRef}
        className="relative min-h-[88px] overflow-hidden bg-background"
      >
        <InitHeroBackground containerRef={bannerRef} compact active />

        <InitOrgPromoBannerLink
          cta={content.cta}
          className="group relative z-10 mx-auto flex min-h-[88px] w-full max-w-7xl cursor-pointer items-center gap-3 px-4 py-3 transition-opacity hover:opacity-90 sm:gap-4 sm:px-6"
        >
          <div className="flex min-w-0 shrink-0 items-center gap-2 sm:gap-3">
            <InitWordmark className="shrink-0 text-[22px] text-foreground sm:text-[26px]" />
            <span className="hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground sm:inline">
              {content.dateRangeLabel}
            </span>
          </div>

          <div className="hidden min-w-0 flex-1 items-center gap-2 md:flex">
            <Badge
              variant={BADGE_VARIANT[content.phase]}
              className="text-[10px] shrink-0"
            >
              {content.badgeLabel}
            </Badge>
            <p className="min-w-0 truncate text-[13px] text-muted-foreground">
              <span className="font-medium text-foreground">
                {content.headline}
              </span>
              <span className="hidden lg:inline">
                {' '}
                - {content.description}
              </span>
            </p>
          </div>

          <div className="ml-auto flex shrink-0 items-center gap-2">
            <Badge
              variant={BADGE_VARIANT[content.phase]}
              className={cn('text-[10px] shrink-0 md:hidden')}
            >
              {content.badgeLabel}
            </Badge>
            <Button
              variant="brandCta"
              size="sm"
              className="pointer-events-none h-8 text-[13px]"
              tabIndex={-1}
              aria-hidden
            >
              {content.cta.label}
            </Button>
          </div>
        </InitOrgPromoBannerLink>
      </div>
    </div>
  )
}
