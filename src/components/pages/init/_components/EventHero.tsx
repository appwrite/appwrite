import { assetUrl } from '@/lib/asset-url'
import { INIT_PRIZES_SECTION_ID } from '@/lib/init/init-section-ids'
import { formatInitCappedCount } from '@/lib/init/presence'
import type {
  InitDisplayEvent,
  LaunchEventLiveBanner,
  LaunchEventOnlineUser,
} from '@/lib/init/types'
import type { ReactNode } from 'react'
import { forwardRef, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  INIT_COLLAPSED_HEADER_HEIGHT_PX,
  useInitScrollSpyDay,
} from '@/lib/init/use-init-scroll-spy-day'
import {
  useInitPresence,
  useInitPresenceActivity,
} from '@/lib/init/init-presence-context'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'
import { buildInitPlayingWithJoolActivity } from '@/lib/init/init-presence-activity'
import { InitPresenceUserAvatar } from '@/components/pages/init/_components/InitPresenceUserAvatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { ChevronRight } from 'lucide-react'
import { EventCtaButton } from '../shared/EventCtas'
import { InitCollapsedDayNav } from './InitCollapsedDayNav'
import { InitHeroBackground } from './InitHeroBackground'
import { useInitTicketVideoRecording } from '@/lib/init/init-ticket-video-recording-context'
import { InitWordmark } from './InitWordmark'
import { useInitHref } from '@/lib/init/use-init-href'
import { useT } from '@/lib/i18n/translate'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'

/** Tailwind `sm` - skip sticky-header Jool on phones for performance. */
const STICKY_JOOL_MIN_WIDTH_PX = 640
const HERO_ONLINE_EASE: [number, number, number, number] = [0.22, 1, 0.36, 1]
/** `size-6` avatar minus `-space-x-2` overlap. */
const HERO_AVATAR_UNFOLD_PX = 16
const HERO_AVATAR_UNFOLD_SPRING = {
  type: 'spring' as const,
  stiffness: 420,
  damping: 28,
  mass: 0.75,
}

const HeroOnlineAvatarStack = forwardRef<
  HTMLDivElement,
  {
    users: LaunchEventOnlineUser[]
    count: number
    countCapped: boolean
    reduceMotion: boolean | null
  }
>(function HeroOnlineAvatarStack(
  { users, count, countCapped, reduceMotion },
  ref,
) {
  const faces = users.slice(0, 4)

  return (
    <motion.div
      ref={ref}
      className="flex items-center justify-center gap-3"
      initial={reduceMotion ? false : { opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={reduceMotion ? undefined : { opacity: 0 }}
      transition={{ duration: 0.28, ease: HERO_ONLINE_EASE }}
    >
      <div className="flex -space-x-2">
        {faces.map((user, index) => (
          <motion.span
            key={user.id}
            className="relative inline-flex origin-left [transform:translateZ(0)] [backface-visibility:hidden]"
            style={{ zIndex: index }}
            initial={
              reduceMotion
                ? false
                : {
                    opacity: 0,
                    scale: 0.55,
                    x: -index * HERO_AVATAR_UNFOLD_PX,
                  }
            }
            animate={{ opacity: 1, scale: 1, x: 0 }}
            transition={{
              ...HERO_AVATAR_UNFOLD_SPRING,
              delay: 0.05 + index * 0.09,
            }}
          >
            <InitPresenceUserAvatar
              user={user}
              displayName={user.name}
              size="sm"
              className="ring-2 ring-background"
            />
          </motion.span>
        ))}
      </div>
      <motion.span
        className="text-[12px] tabular-nums text-muted-foreground"
        initial={reduceMotion ? false : { opacity: 0, x: -8 }}
        animate={{ opacity: 1, x: 0 }}
        transition={{
          duration: 0.38,
          ease: HERO_ONLINE_EASE,
          delay: reduceMotion ? 0 : 0.12 + faces.length * 0.09,
        }}
      >
        {formatInitCappedCount(count, countCapped)} online now
      </motion.span>
    </motion.div>
  )
})

interface EventHeroProps {
  event: InitDisplayEvent
  /** Optional content above the date label (e.g. variant badge on preview pages). */
  headerAddon?: ReactNode
  /** Shown in the collapsed sticky bar when the user scrolls past the hero. */
  liveBanner?: LaunchEventLiveBanner
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
  dayNumbers,
  activeDay,
  particlesActive,
  days,
}: {
  event: InitDisplayEvent
  visible: boolean
  liveBanner?: LaunchEventLiveBanner
  bounds: MainBounds | null
  dayNumbers: number[]
  activeDay: number
  particlesActive: boolean
  days: InitDisplayEvent['days']
}) {
  const t = useT()
  const barRef = useRef<HTMLDivElement>(null)
  const showDayNav = dayNumbers.length > 0
  const showStickyJool = useMediaMinWidth(STICKY_JOOL_MIN_WIDTH_PX)
  const { setTransientActivity } = useInitPresenceActivity()
  const resolvedLiveHref = useInitHref(liveBanner?.href)
  const isStartingSoon = liveBanner?.mode === 'startingSoon'
  const handleJoolInteractionStart = useCallback(() => {
    setTransientActivity(buildInitPlayingWithJoolActivity())
  }, [setTransientActivity])
  const handleJoolInteractionEnd = useCallback(() => {
    setTransientActivity(null)
  }, [setTransientActivity])

  return (
    <div
      ref={barRef}
      aria-hidden={!visible}
      // opacity + translate leave the day buttons and Watch link in the tab
      // order, so keyboard users hit six invisible stops on a fixed bar the
      // browser cannot scroll into view. aria-hidden alone does not remove them.
      inert={!visible ? true : undefined}
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
        height: INIT_COLLAPSED_HEADER_HEIGHT_PX,
      }}
    >
      {visible && showStickyJool ? (
        <InitHeroBackground
          containerRef={barRef}
          compact
          active={particlesActive}
          onInteractionStart={handleJoolInteractionStart}
          onInteractionEnd={handleJoolInteractionEnd}
        />
      ) : null}
      <div
        className={cn(
          'absolute inset-0 z-20 flex items-center',
          visible && 'pointer-events-auto',
        )}
      >
        {showDayNav ? (
          <div className="relative mx-auto flex w-full max-w-7xl items-center px-4 sm:px-6">
            <div className="relative z-10 hidden min-w-0 shrink-0 items-center gap-2 sm:flex sm:gap-3">
              <InitWordmark className="shrink-0 text-[20px] text-foreground sm:text-[22px]" />
              <span className="hidden truncate text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground sm:inline">
                {event.dateRangeLabel}
              </span>
            </div>

            <div
              className={cn(
                // Keep the nav in flow until the bar is wide enough to center it
                // over the row: the fixed insets that reserved space for the
                // wordmark/date and the live badge + Watch button were smaller
                // than those columns, so the day buttons rendered underneath.
                'flex min-w-0 flex-1 items-center justify-center',
                'lg:pointer-events-none lg:absolute lg:inset-0 lg:flex-none lg:px-36',
              )}
            >
              <div className="pointer-events-auto w-full min-w-0 max-w-full sm:max-w-none">
                <InitCollapsedDayNav
                  days={days}
                  activeDay={activeDay}
                  currentDay={event.currentDay}
                />
              </div>
            </div>

            <div className="relative z-10 ms-auto flex shrink-0 justify-end">
              {liveBanner ? (
                <div className="flex items-center gap-1.5 sm:gap-2">
                  <Badge
                    variant={isStartingSoon ? 'warning' : 'error'}
                    className="text-[10px] shrink-0"
                  >
                    {isStartingSoon ? t('Starting soon') : t('Live')}
                  </Badge>
                  {resolvedLiveHref ? (
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-7 shrink-0 px-2 text-[12px] sm:px-3"
                      asChild
                    >
                      <a
                        href={assetUrl(resolvedLiveHref.href)}
                        {...(resolvedLiveHref.external
                          ? { target: '_blank', rel: 'noopener noreferrer' }
                          : {})}
                      >
                        {t('Watch')}
                        <ChevronRight className="size-3.5" />
                      </a>
                    </Button>
                  ) : (
                    <span className="hidden max-w-[120px] truncate text-[12px] font-medium text-foreground sm:inline">
                      {t(liveBanner.title)}
                    </span>
                  )}
                </div>
              ) : null}
            </div>
          </div>
        ) : (
          <div className="mx-auto flex w-full max-w-7xl items-center gap-3 px-4 sm:gap-4 sm:px-6">
            <InitWordmark className="shrink-0 text-[22px] text-foreground sm:text-[24px]" />
            {liveBanner ? (
              <>
                <div className="flex min-w-0 flex-1 items-center gap-2">
                  <Badge
                    variant={isStartingSoon ? 'warning' : 'error'}
                    className="text-[10px] shrink-0"
                  >
                    {isStartingSoon ? t('Starting soon') : t('Live now')}
                  </Badge>
                  <span className="truncate text-[13px] font-medium text-foreground">
                    {t(liveBanner.title)}
                  </span>
                </div>
                {resolvedLiveHref ? (
                  <Button variant="outline" size="sm" className="h-7 shrink-0 text-[12px]" asChild>
                    <a
                      href={assetUrl(resolvedLiveHref.href)}
                      {...(resolvedLiveHref.external
                        ? { target: '_blank', rel: 'noopener noreferrer' }
                        : {})}
                    >
                      {t('Watch')}
                      <ChevronRight className="size-3.5" />
                    </a>
                  </Button>
                ) : null}
              </>
            ) : (
              <span className="hidden text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground sm:inline">
                {event.dateRangeLabel}
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

export function EventHero({ event, headerAddon, liveBanner }: EventHeroProps) {
  const t = useT()
  const { data: account } = useQuery(consoleAccountQueryOptions())
  const heroRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)
  const [isCollapsed, setIsCollapsed] = useState(false)
  const [collapseEnabled, setCollapseEnabled] = useState(true)
  const [mainBounds, setMainBounds] = useState<MainBounds | null>(null)
  const dayNumbers = useMemo(() => event.days.map((day) => day.day), [event.days])
  const activeDay = useInitScrollSpyDay(dayNumbers)
  const { isCapturing: isTicketVideoCapturing } = useInitTicketVideoRecording()
  const { isReady: isPresenceReady } = useInitPresence()
  const reduceMotion = useReducedMotion()
  const { setTransientActivity } = useInitPresenceActivity()
  const handleJoolInteractionStart = useCallback(() => {
    setTransientActivity(buildInitPlayingWithJoolActivity())
  }, [setTransientActivity])
  const handleJoolInteractionEnd = useCallback(() => {
    setTransientActivity(null)
  }, [setTransientActivity])

  const giveawayDiscordCta = useMemo(() => {
    if (!event.giveaway?.secondaryCtaHref) return null
    return {
      label: t(event.giveaway.secondaryCtaLabel ?? 'Join on Discord'),
      href: event.giveaway.secondaryCtaHref,
      external: true,
    }
  }, [event.giveaway, t])

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
    if (!collapseEnabled || isTicketVideoCapturing) return

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
  }, [collapseEnabled, isTicketVideoCapturing])

  return (
    <>
      {collapseEnabled ? (
        <CollapsedHeroBar
          event={event}
          visible={isCollapsed && !isTicketVideoCapturing}
          liveBanner={liveBanner}
          bounds={mainBounds}
          dayNumbers={dayNumbers}
          activeDay={activeDay}
          particlesActive={!isTicketVideoCapturing}
          days={event.days}
        />
      ) : null}

      <div
        ref={heroRef}
        className="relative min-h-[420px] overflow-hidden border-b border-border bg-background sm:min-h-[480px]"
      >
        <InitHeroBackground
          containerRef={heroRef}
          active={!isTicketVideoCapturing}
          onInteractionStart={handleJoolInteractionStart}
          onInteractionEnd={handleJoolInteractionEnd}
        />

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
          <InitWordmark className="mt-6 text-[clamp(48px,12vw,96px)] text-foreground" />
          <p className="mx-auto mt-4 max-w-xl text-[14px] leading-relaxed text-muted-foreground">
            {event.description}
          </p>
          {!event.isRecapMode ? (
            <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
              {!account ? (
                <EventCtaButton cta={event.primaryCta} variant="brandCta" size="lg" />
              ) : event.prizes ? (
                <>
                  <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
                    <a href={assetUrl(`#${INIT_PRIZES_SECTION_ID}`)}>
                      {event.giveaway?.ctaLabel ?? 'View prizes'}
                    </a>
                  </Button>
                  {giveawayDiscordCta ? (
                    <EventCtaButton
                      cta={giveawayDiscordCta}
                      variant="outline"
                      size="lg"
                    />
                  ) : null}
                </>
              ) : null}
            </div>
          ) : null}
          {!event.isRecapMode && event.presenceEnabled ? (
            <div className="mt-8 flex min-h-8 items-center justify-center gap-3">
              <AnimatePresence>
                {isPresenceReady && event.onlineCount > 0 ? (
                  <HeroOnlineAvatarStack
                    key="online-now"
                    users={event.onlineUsers}
                    count={event.onlineCount}
                    countCapped={event.onlineCountCapped}
                    reduceMotion={reduceMotion}
                  />
                ) : null}
              </AnimatePresence>
            </div>
          ) : null}
        </div>
      </div>

      <div ref={sentinelRef} className="h-px w-full shrink-0" aria-hidden />
    </>
  )
}
