import { Link } from '@tanstack/react-router'
import { useMemo } from 'react'
import type { ReactNode } from 'react'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { Badge } from '@/components/ui/badge'
import { getEnvProfileFeatures } from '@/lib/console-profiles'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  getInitOrgPromoBannerContent,
  type InitOrgPromoPhase,
} from '@/lib/init/org-promo-banner'
import type { LaunchEventCta } from '@/lib/init/types'
import { useT } from '@/lib/i18n/translate'

const BADGE_VARIANT: Record<InitOrgPromoPhase, 'info' | 'success' | 'warning'> =
  {
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
      <Link to={cta.to} className={className}>
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
  const t = useT()
  const { mockInitCurrentDay } = useDebugOverrides()
  const content = useMemo(() => {
    if (!getEnvProfileFeatures().init) return null
    return getInitOrgPromoBannerContent({ mockCurrentDay: mockInitCurrentDay })
  }, [mockInitCurrentDay])
  if (!content) return null

  return (
    <div className="relative w-full shrink-0 overflow-hidden border-b border-border bg-background">
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-60"
      />
      <InitOrgPromoBannerLink
        cta={content.cta}
        className="group/banner relative flex h-14 w-full cursor-pointer items-center justify-center gap-2 px-4 sm:gap-2.5 sm:px-6"
      >
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 bg-muted/0 transition-colors duration-300 ease-out group-hover/banner:bg-muted/30"
        />
        <InitWordmark className="relative z-10 shrink-0 text-[20px] text-foreground transition-colors duration-300 ease-out group-hover/banner:text-foreground sm:text-[22px]" />
        <span
          aria-hidden
          className="relative z-10 hidden shrink-0 text-muted-foreground/40 sm:inline"
        >
          ·
        </span>
        <span className="relative z-10 hidden shrink-0 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground md:inline">
          {content.dateRangeLabel}
        </span>
        {content.badgeLabel ? (
          <>
            <span
              aria-hidden
              className="relative z-10 hidden shrink-0 text-muted-foreground/40 md:inline"
            >
              ·
            </span>
            <Badge
              variant={BADGE_VARIANT[content.phase]}
              className="relative z-10 text-[10px] shrink-0"
            >
              {t(content.badgeLabel)}
            </Badge>
          </>
        ) : null}
        <span
          aria-hidden
          className="relative z-10 hidden shrink-0 text-muted-foreground/40 sm:inline"
        >
          ·
        </span>
        <p className="relative z-10 min-w-0 truncate text-[13px] text-muted-foreground transition-colors duration-300 ease-out group-hover/banner:text-foreground/80">
          {t(content.message)}
        </p>
      </InitOrgPromoBannerLink>
    </div>
  )
}
