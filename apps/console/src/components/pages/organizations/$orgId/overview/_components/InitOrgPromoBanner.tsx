import { Link } from '@tanstack/react-router'
import { useEffect, useMemo, useRef } from 'react'
import type { ReactNode, RefObject } from 'react'
import { useTheme } from 'next-themes'
import { InitWordmark } from '@/components/pages/init/_components/InitWordmark'
import { Badge } from '@/components/ui/badge'
import {
  INIT5_FIVE_GLYPH_PATH,
  INIT5_FIVE_GLYPH_VIEWBOX,
} from '@/lib/init/init5-hero/five-glyph'
import {
  createInitBannerStorm,
  type InitBannerStorm,
} from '@/lib/init/init5-hero/banner-storm'
import { isDarkChrome, readPalette } from '@/lib/init/init5-hero/palette'
import { getEnvProfileFeatures } from '@/lib/console-profiles'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  getInitOrgPromoBannerContent,
  type InitOrgPromoPhase,
} from '@/lib/init/org-promo-banner'
import type { LaunchEventCta } from '@/lib/init/types'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Init 5 wears the hero's light trails; earlier weeks keep the plain mark. */
const LIGHT_TRAIL_EVENT_IDS = new Set(['init-nov-2026'])

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

/**
 * Init 5: the hero's storm along the bar, fog drifting in banks around the
 * init_5 mark with lightning flashing inside it.
 */
function Init5BannerStorm({
  markRef,
}: {
  markRef: RefObject<SVGSVGElement | null>
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const stormRef = useRef<InitBannerStorm | null>(null)
  const { resolvedTheme } = useTheme()
  const isDark = isDarkChrome(resolvedTheme)

  useEffect(() => {
    const canvas = canvasRef.current
    const banner = canvas?.parentElement
    if (!canvas || !banner) return
    const reducedMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
    const storm = createInitBannerStorm(canvas, {
      palette: readPalette(banner, isDarkChrome(resolvedTheme)),
      reducedMotion,
    })
    if (!storm) return
    stormRef.current = storm

    const layout = () => {
      const box = banner.getBoundingClientRect()
      storm.resize(box.width, box.height)
      const mark = markRef.current?.getBoundingClientRect()
      storm.setMark(
        mark ? mark.left + mark.width / 2 - box.left : box.width / 2,
        mark ? mark.top + mark.height / 2 - box.top : box.height / 2,
      )
    }
    layout()
    const resizeObserver = new ResizeObserver(layout)
    resizeObserver.observe(banner)
    const visibility = new IntersectionObserver(([entry]) =>
      storm.setActive(Boolean(entry?.isIntersecting)),
    )
    visibility.observe(banner)

    return () => {
      resizeObserver.disconnect()
      visibility.disconnect()
      storm.dispose()
      stormRef.current = null
    }
    // The storm is built once; theme changes repaint it below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [markRef])

  useEffect(() => {
    const banner = canvasRef.current?.parentElement
    if (banner) stormRef.current?.setPalette(readPalette(banner, isDark))
  }, [isDark, resolvedTheme])

  return (
    <canvas
      ref={canvasRef}
      aria-hidden
      className={cn(
        'pointer-events-none absolute inset-0 h-full w-full',
        // Light adds to the dark bar, as in the hero.
        isDark && 'mix-blend-screen',
      )}
    />
  )
}

export function InitOrgPromoBanner() {
  const t = useT()
  const { mockInitCurrentDay, mockInitActiveEventId } = useDebugOverrides()
  const fiveRef = useRef<SVGSVGElement>(null)
  const content = useMemo(() => {
    if (!getEnvProfileFeatures().init) return null
    return getInitOrgPromoBannerContent({
      currentDay: mockInitCurrentDay,
      activeEventId: mockInitActiveEventId,
    })
  }, [mockInitCurrentDay, mockInitActiveEventId])
  if (!content) return null

  const lightTrails = LIGHT_TRAIL_EVENT_IDS.has(content.eventId)

  return (
    <div className="relative w-full shrink-0 overflow-hidden border-b border-border bg-background">
      {lightTrails ? (
        <>
          <Init5BannerStorm markRef={fiveRef} />
          <div aria-hidden className="init5-banner-sweep" />
        </>
      ) : (
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-60"
        />
      )}
      <InitOrgPromoBannerLink
        cta={content.cta}
        className="group/banner relative flex h-14 w-full cursor-pointer items-center justify-center gap-2 px-4 sm:gap-2.5 sm:px-6"
      >
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 bg-muted/0 transition-colors duration-300 ease-out group-hover/banner:bg-muted/30"
        />
        <InitWordmark
          lightTrails={lightTrails}
          className="relative z-10 shrink-0 text-[20px] text-foreground transition-colors duration-300 ease-out group-hover/banner:text-foreground sm:text-[22px]"
        />
        {lightTrails ? (
          <svg
            ref={fiveRef}
            viewBox={INIT5_FIVE_GLYPH_VIEWBOX}
            aria-label="5"
            role="img"
            className="relative z-10 -ms-0.5 h-[1.05em] w-auto shrink-0 text-[20px] text-foreground sm:text-[22px]"
          >
            <path fill="currentColor" d={INIT5_FIVE_GLYPH_PATH} />
          </svg>
        ) : null}
        <span
          aria-hidden
          className="relative z-10 hidden shrink-0 text-muted-foreground/40 sm:inline"
        >
          ·
        </span>
        <span className="relative z-10 hidden shrink-0 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground md:inline">
          {t(content.dateRangeLabel)}
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
            {content.day ? (
              <span className="relative z-10 hidden shrink-0 text-[10px] font-semibold uppercase tracking-[0.2em] text-foreground sm:inline">
                {t('Day')} {content.day}
              </span>
            ) : null}
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
