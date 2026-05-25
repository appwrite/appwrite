import type { LaunchEvent, LaunchEventLiveBanner } from '@/lib/init/types'
import type { ReactNode } from 'react'
import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { ChevronRight } from 'lucide-react'
import { EventCtaButton } from '../shared/EventCtas'
import { InitHeroBackground } from './InitHeroBackground'

const COLLAPSED_HEIGHT_PX = 56

interface EventHeroProps {
  event: LaunchEvent
  /** Optional content above the date label (e.g. variant badge on preview pages). */
  headerAddon?: ReactNode
  /** Shown in the collapsed sticky bar when the user scrolls past the hero. */
  liveBanner?: LaunchEventLiveBanner
}

function InitWordmark({
  event,
  className,
}: {
  event: LaunchEvent
  className?: string
}) {
  return (
    <p
      className={cn(
        'font-aeonik-pro font-bold italic leading-none tracking-tight text-foreground',
        className,
      )}
    >
      {event.name}
      <span className="text-[var(--brand-cta)]">_</span>
    </p>
  )
}

type MainBounds = {
  left: number
  width: number
  top: number
}

function CollapsedHeroBar({
  event,
  visible,
  liveBanner,
  bounds,
}: {
  event: LaunchEvent
  visible: boolean
  liveBanner?: LaunchEventLiveBanner
  bounds: MainBounds | null
}) {
  const barRef = useRef<HTMLDivElement>(null)

  return (
    <div
      ref={barRef}
      aria-hidden={!visible}
      className={cn(
        'fixed z-[15] overflow-hidden border-b border-border',
        'bg-background/95 backdrop-blur-sm supports-[backdrop-filter]:bg-background/80',
        'transition-[transform,opacity] duration-300 ease-out',
        visible ? 'translate-y-0 opacity-100' : 'pointer-events-none -translate-y-full opacity-0',
      )}
      style={{
        top: bounds?.top ?? 0,
        left: bounds?.left ?? 0,
        width: bounds?.width ?? '100%',
        height: COLLAPSED_HEIGHT_PX,
      }}
    >
      <InitHeroBackground containerRef={barRef} compact />
      <div
        className={cn(
          'absolute inset-0 z-20 flex items-center',
          visible && 'pointer-events-auto',
        )}
      >
        <div className="mx-auto flex w-full max-w-7xl items-center gap-3 px-4 sm:gap-4 sm:px-6">
          <InitWordmark event={event} className="shrink-0 text-[22px] sm:text-[24px]" />
          {liveBanner ? (
            <>
              <div className="flex min-w-0 flex-1 items-center gap-2">
                <Badge variant="error" className="text-[10px] shrink-0">
                  Live now
                </Badge>
                <span className="truncate text-[13px] font-medium text-foreground">
                  {liveBanner.title}
                </span>
              </div>
              {liveBanner.href ? (
                <Button variant="outline" size="sm" className="h-7 shrink-0 text-[12px]" asChild>
                  <a href={liveBanner.href} target="_blank" rel="noopener noreferrer">
                    Watch
                    <ChevronRight className="size-3.5" />
                  </a>
                </Button>
              ) : null}
            </>
          ) : (
            <span className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
              {event.dateRangeLabel}
            </span>
          )}
        </div>
      </div>
    </div>
  )
}

export function EventHero({ event, headerAddon, liveBanner }: EventHeroProps) {
  const { data: account } = useQuery(consoleAccountQueryOptions())
  const firstName = account?.name?.trim().split(/\s+/)[0]
  const heroRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)
  const [isCollapsed, setIsCollapsed] = useState(false)
  const [collapseEnabled, setCollapseEnabled] = useState(true)
  const [mainBounds, setMainBounds] = useState<MainBounds | null>(null)

  useEffect(() => {
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    setCollapseEnabled(!prefersReducedMotion)
  }, [])

  useEffect(() => {
    const main = document.getElementById('main-content')
    if (!main) return

    let frame = 0

    const updateBounds = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        const rect = main.getBoundingClientRect()
        setMainBounds({
          left: rect.left,
          width: rect.width,
          top: rect.top,
        })
      })
    }

    updateBounds()

    const resizeObserver = new ResizeObserver(updateBounds)
    resizeObserver.observe(main)

    window.addEventListener('resize', updateBounds)
    main.addEventListener('scroll', updateBounds, { passive: true })

    return () => {
      cancelAnimationFrame(frame)
      resizeObserver.disconnect()
      window.removeEventListener('resize', updateBounds)
      main.removeEventListener('scroll', updateBounds)
    }
  }, [])

  useEffect(() => {
    if (!collapseEnabled) return

    const sentinel = sentinelRef.current
    const main = document.getElementById('main-content')
    if (!sentinel || !main) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) {
          setIsCollapsed(true)
          return
        }

        if (entry.boundingClientRect.top >= -1) {
          setIsCollapsed(false)
        }
      },
      { root: main, threshold: 0 },
    )

    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [collapseEnabled])

  return (
    <>
      {collapseEnabled ? (
        <CollapsedHeroBar
          event={event}
          visible={isCollapsed}
          liveBanner={liveBanner}
          bounds={mainBounds}
        />
      ) : null}

      <div
        ref={heroRef}
        className="relative min-h-[420px] overflow-hidden border-b border-border bg-background sm:min-h-[480px]"
      >
        <InitHeroBackground containerRef={heroRef} />

        <div className="relative z-10 mx-auto flex min-h-[420px] w-full max-w-7xl flex-col items-center justify-center px-4 py-12 text-center sm:min-h-[480px] sm:px-6 sm:py-16">
          {headerAddon}
          <p
            className={cn(
              'text-[11px] font-semibold uppercase tracking-[0.25em] text-foreground',
              headerAddon && 'mt-4',
            )}
          >
            {event.dateRangeLabel}
          </p>
          <InitWordmark
            event={event}
            className="mt-6 text-[clamp(48px,12vw,96px)]"
          />
          <p className="mx-auto mt-4 max-w-xl text-[14px] leading-relaxed text-muted-foreground">
            {event.description}
          </p>
          {firstName ? (
            <p className="mt-2 text-[13px] text-muted-foreground">Welcome, {firstName}</p>
          ) : null}
          <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
            <EventCtaButton cta={event.primaryCta} variant="brandCta" size="lg" />
            {event.secondaryCta ? (
              <EventCtaButton cta={event.secondaryCta} variant="outline" size="lg" />
            ) : null}
          </div>
          {event.onlineCount > 0 ? (
            <div className="mt-8 flex items-center justify-center gap-3">
              <div className="flex -space-x-2">
                {event.onlineUsers.slice(0, 4).map((user) => (
                  <InitialsAvatar
                    key={user.id}
                    name={user.name}
                    size="sm"
                    className="ring-2 ring-background"
                  />
                ))}
              </div>
              <span className="text-[12px] text-muted-foreground">
                {event.onlineCount.toLocaleString()} online now
              </span>
            </div>
          ) : null}
        </div>
      </div>

      <div ref={sentinelRef} className="h-px w-full shrink-0" aria-hidden />
    </>
  )
}
