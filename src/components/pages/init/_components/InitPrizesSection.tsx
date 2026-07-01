import { INIT_PRIZES_SECTION_ID } from '@/lib/init/init-section-ids'
import type {
  InitDisplayEvent,
  LaunchEventDailyPrize,
  LaunchEventGrandPrize,
  LaunchEventPrizeVisual,
} from '@/lib/init/types'
import { useInitThemeImageSrc } from '@/lib/init/use-init-theme-image'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import {
  buildInitViewingDailyPrizeActivity,
  buildInitViewingGrandPrizeActivity,
} from '@/lib/init/init-presence-activity'
import { cn } from '@/lib/utils'
import { isExternalInitHref } from '@/lib/init/links'
import { ArrowUpRight, Gift, Sparkles } from 'lucide-react'
import { useEffect, useState } from 'react'
import {
  PRIZE_CARD_BG,
  PRIZE_IMAGE_FRAME,
  PRIZE_IMAGE_HOVER_ZOOM,
  PRIZE_IMAGE_HOVER_ZOOM_COMPACT,
  PRIZE_IMAGE_INSET,
  PRIZE_SWAG_IMAGE_OPACITY,
  PRIZE_SWAG_IMAGE_OPACITY_COMPACT,
} from './prize-image-styles'
import { InitGiveawayRaffleBack } from './InitGiveawayRaffleBack'
import { InitGrandPrizeRevealBack } from './InitGrandPrizeRevealBack'
import { useInitGiveawayRaffleContext } from './init-giveaway-raffle-context'

/** Compact ratio for the 2×2 daily cells. */
const DAILY_IMAGE_ASPECT = 'aspect-[3/2]'

/** Matches the sidebar giveaway promo card. */
const GRAND_IMAGE_ASPECT = 'aspect-[4/3]'

const PRIZE_LINE = 'min-w-0 truncate'

const GRID_FLIP_TRANSITION =
  'transition-transform duration-700 ease-in-out [transform-style:preserve-3d]'

function usePrizePresenceHandlers(activity: string) {
  const { setTransientActivity } = useInitPresenceActivity()

  return {
    onMouseEnter: () => setTransientActivity(activity),
    onMouseLeave: () => setTransientActivity(null),
    onFocus: () => setTransientActivity(activity),
    onBlur: () => setTransientActivity(null),
  }
}

const PLATFORM_META = {
  youtube: { label: 'YouTube', icon: '/icons/youtube.svg' },
  discord: { label: 'Discord', icon: '/icons/discord-simple.svg' },
  reddit: { label: 'Reddit', icon: '/icons/reddit.svg' },
} as const

const DAILY_PLACEMENT = [
  'border-b border-e border-border sm:col-start-1 sm:row-start-2 lg:col-start-1 lg:row-start-1',
  'border-b border-border sm:col-start-2 sm:row-start-2 lg:col-start-2 lg:row-start-1',
  'border-b border-e border-border sm:col-start-1 sm:row-start-3 lg:col-start-1 lg:row-start-2 lg:border-b-0',
  'border-b border-border sm:col-start-2 sm:row-start-3 lg:col-start-2 lg:row-start-2 lg:border-b-0',
] as const

interface InitPrizesSectionProps {
  event: InitDisplayEvent
}

function PrizeImage({
  visual,
  placeholderIcon: PlaceholderIcon,
  subdued = false,
  compact = false,
  className,
}: {
  visual?: LaunchEventPrizeVisual
  placeholderIcon: typeof Gift
  subdued?: boolean
  compact?: boolean
  className?: string
}) {
  const imageSrc = useInitThemeImageSrc(
    visual?.imageSrcLight ?? '',
    visual?.imageSrcDark ?? '',
  )
  const hasImage = Boolean(visual?.imageSrcLight && visual?.imageSrcDark)

  return (
    <>
      {hasImage && visual ? (
        <img
          src={imageSrc}
          alt={visual.imageAlt}
          className={cn(
            'absolute inset-0 size-full object-cover object-center',
            compact ? PRIZE_IMAGE_HOVER_ZOOM_COMPACT : PRIZE_IMAGE_HOVER_ZOOM,
            subdued &&
              (compact ? PRIZE_SWAG_IMAGE_OPACITY_COMPACT : PRIZE_SWAG_IMAGE_OPACITY),
            className,
          )}
          loading="lazy"
          decoding="async"
        />
      ) : (
        <div className={cn('absolute inset-0 flex items-center justify-center', className)}>
          <span className="flex size-10 items-center justify-center rounded-full border border-border/60 bg-background/50">
            <PlaceholderIcon className="size-4 text-muted-foreground/60" aria-hidden />
          </span>
        </div>
      )}
    </>
  )
}

function DailyPrizeCell({
  giveaway,
  className,
}: {
  giveaway: LaunchEventDailyPrize
  className?: string
}) {
  const meta = PLATFORM_META[giveaway.platform]
  const presenceHandlers = usePrizePresenceHandlers(
    buildInitViewingDailyPrizeActivity(giveaway.day, giveaway.prizeDescription),
  )
  const shellClass = cn(
    'group flex h-full min-h-0 min-w-0 flex-col transition-colors hover:bg-accent/15',
    PRIZE_CARD_BG,
    className,
  )

  const content = (
    <>
      <div
        className={cn(
          'w-full shrink-0 border-b border-border',
          PRIZE_IMAGE_INSET,
        )}
      >
        <div className={cn(PRIZE_IMAGE_FRAME, DAILY_IMAGE_ASPECT)}>
          <PrizeImage visual={giveaway.visual} placeholderIcon={Gift} subdued compact />
        </div>
      </div>

      <div className="flex flex-1 flex-col justify-between gap-3 p-4">
        <div className="flex min-w-0 items-start justify-between gap-3">
          <p className={cn(PRIZE_LINE, 'text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground')}>
            Day {giveaway.day}
            <span className="mx-1.5 text-border">·</span>
            {giveaway.dateLabel}
          </p>
          {giveaway.href ? (
            <ArrowUpRight
              className="size-3.5 shrink-0 text-muted-foreground/70 transition-colors group-hover:text-foreground"
              aria-hidden
            />
          ) : null}
        </div>

        <div className="space-y-2">
          <div className="min-w-0 space-y-1.5">
            <h4 className={cn(PRIZE_LINE, 'text-[13px] font-medium text-foreground')} title={giveaway.sessionTitle}>
              {giveaway.sessionTitle}
            </h4>
            <div className="flex min-w-0 items-center gap-1.5 text-[11px] text-muted-foreground">
              <img src={meta.icon} alt="" className="size-3 shrink-0 opacity-70" aria-hidden />
              <span className={PRIZE_LINE}>
                {meta.label} · {giveaway.timeLabel}
              </span>
            </div>
          </div>

          <p className={cn(PRIZE_LINE, 'text-[12px] text-foreground/90')} title={giveaway.prizeDescription}>
            {giveaway.prizeDescription}
          </p>
        </div>
      </div>
    </>
  )

  if (giveaway.href) {
    const external = isExternalInitHref(giveaway.href)
    return (
      <a
        href={giveaway.href}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className={shellClass}
        {...presenceHandlers}
      >
        {content}
      </a>
    )
  }

  return (
    <article className={shellClass} {...presenceHandlers}>
      {content}
    </article>
  )
}

function GrandPrizeCell({
  grandPrize,
  className,
}: {
  grandPrize: LaunchEventGrandPrize
  className?: string
}) {
  const platformMeta = grandPrize.platform ? PLATFORM_META[grandPrize.platform] : null
  const presenceHandlers = usePrizePresenceHandlers(
    buildInitViewingGrandPrizeActivity(grandPrize.title),
  )

  return (
    <article
      className={cn(
        'group flex h-full min-h-0 min-w-0 flex-col transition-colors hover:bg-accent/15',
        PRIZE_CARD_BG,
        className,
      )}
      {...presenceHandlers}
    >
      <div
        className={cn(
          'w-full shrink-0 border-b border-border',
          PRIZE_IMAGE_INSET,
        )}
      >
        <div className={cn(PRIZE_IMAGE_FRAME, GRAND_IMAGE_ASPECT)}>
          <PrizeImage visual={grandPrize.visual} placeholderIcon={Sparkles} />
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col justify-center px-5 py-5 sm:px-6 sm:py-6">
        <div className="min-w-0 space-y-3">
          <p className={cn(PRIZE_LINE, 'text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground')}>
            Grand prize · Day {grandPrize.day}
            <span className="mx-1.5 text-border">·</span>
            {grandPrize.dateLabel}
          </p>
          <h4
            className={cn(
              PRIZE_LINE,
              'font-aeonik-pro text-[clamp(18px,2.2vw,24px)] font-normal tracking-tight text-foreground',
            )}
            title={grandPrize.title}
          >
            {grandPrize.title}
          </h4>
          {grandPrize.sessionTitle && platformMeta ? (
            <div className="flex min-w-0 items-center gap-1.5 text-[11px] text-muted-foreground">
              <img src={platformMeta.icon} alt="" className="size-3 shrink-0 opacity-70" aria-hidden />
              <span className={PRIZE_LINE}>
                {platformMeta.label} · {grandPrize.sessionTitle} · {grandPrize.timeLabel}
              </span>
            </div>
          ) : null}
          <p className={cn(PRIZE_LINE, 'text-[12px] text-muted-foreground')} title={grandPrize.eligibility}>
            {grandPrize.eligibility}
          </p>
        </div>
      </div>
    </article>
  )
}

function PrizesGrid({ prizes }: { prizes: NonNullable<InitDisplayEvent['prizes']> }) {
  return (
    <div className={cn('overflow-hidden rounded-xl border border-border', PRIZE_CARD_BG)}>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 lg:grid-rows-2 lg:items-stretch">
        <GrandPrizeCell
          grandPrize={prizes.grandPrize}
          className="order-first border-b border-border sm:col-span-2 sm:row-start-1 lg:col-span-2 lg:col-start-3 lg:row-span-2 lg:row-start-1 lg:border-b-0 lg:border-s"
        />

        {prizes.dailyGiveaways.map((giveaway, index) => (
          <DailyPrizeCell
            key={giveaway.scheduleItemId}
            giveaway={giveaway}
            className={DAILY_PLACEMENT[index] ?? 'border-b border-border'}
          />
        ))}
      </div>
    </div>
  )
}

export function InitPrizesSection({ event }: InitPrizesSectionProps) {
  const prizes = event.prizes
  const raffle = useInitGiveawayRaffleContext()

  if (!prizes || event.isRecapMode || !raffle) return null

  const sectionTitle = prizes.sectionTitle ?? 'Prizes and giveaways'
  const [isGridFlipped, setIsGridFlipped] = useState(false)

  const isRevealOpen = Boolean(raffle.activeGiveaway || raffle.isGrandPrizeRevealOpen)

  useEffect(() => {
    if (isRevealOpen) {
      let flipFrame = 0
      const mountFrame = requestAnimationFrame(() => {
        flipFrame = requestAnimationFrame(() => setIsGridFlipped(true))
      })
      return () => {
        cancelAnimationFrame(mountFrame)
        cancelAnimationFrame(flipFrame)
      }
    }

    setIsGridFlipped(false)
    return undefined
  }, [isRevealOpen])

  return (
    <>
      <div className="border-t border-border" aria-hidden />
      <div className="py-8">
        <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
          <section
            id={INIT_PRIZES_SECTION_ID}
            className="scroll-mt-28 space-y-4"
            aria-labelledby="prizes-heading"
          >
        <div>
          <h3 id="prizes-heading" className="text-[15px] font-semibold text-foreground">
            {sectionTitle}
          </h3>
          {prizes.sectionDescription ? (
            <p className="mt-2 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">
              {prizes.sectionDescription}
            </p>
          ) : null}
        </div>

        <div className="[perspective:1400px]">
          <div
            className={cn(
              'relative',
              GRID_FLIP_TRANSITION,
              isGridFlipped && '[transform:rotateY(180deg)]',
            )}
          >
            <div className="[backface-visibility:hidden]">
              <PrizesGrid prizes={prizes} />
            </div>

            {raffle.activeGiveaway ? (
              <div
                className={cn(
                  'absolute inset-0 min-h-full [backface-visibility:hidden] [transform:rotateY(180deg)]',
                )}
              >
                <InitGiveawayRaffleBack
                  key={raffle.activeGiveaway.day}
                  giveaway={raffle.activeGiveaway}
                  participants={raffle.participants}
                  loadingParticipants={raffle.loadingParticipants}
                  onClose={raffle.close}
                />
              </div>
            ) : raffle.isGrandPrizeRevealOpen && raffle.grandPrize ? (
              <div
                className={cn(
                  'absolute inset-0 min-h-full [backface-visibility:hidden] [transform:rotateY(180deg)]',
                )}
              >
                <InitGrandPrizeRevealBack
                  grandPrize={raffle.grandPrize}
                  participants={raffle.participants}
                  loadingParticipants={raffle.loadingParticipants}
                  onClose={raffle.close}
                />
              </div>
            ) : null}
          </div>
        </div>
          </section>
        </div>
      </div>
      <div className="border-t border-border" aria-hidden />
    </>
  )
}
