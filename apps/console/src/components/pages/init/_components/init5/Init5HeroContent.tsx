import { motion } from 'motion/react'
import type { CSSProperties, ReactNode } from 'react'
import type { InitDisplayEvent } from '@/lib/init/types'
import { cn } from '@/lib/utils'
import { InitWordmark } from '../InitWordmark'
import { INIT5_HERO_ANCHOR_ATTRIBUTE } from './init5-hero-anchor'
import { useInit5Hero } from './init5-hero-context'
import { Init5HeroCountdown } from './Init5HeroCountdown'
import { Init5HeroVariantSwitcher } from './Init5HeroVariantSwitcher'

const REVEAL_EASE: [number, number, number, number] = [0.16, 1, 0.3, 1]
const WIPE_EASE: [number, number, number, number] = [0.65, 0, 0.35, 1]

function Reveal({
  children,
  delay,
  reduceMotion,
  className,
}: {
  children: ReactNode
  delay: number
  reduceMotion: boolean
  className?: string
}) {
  if (reduceMotion) return <div className={className}>{children}</div>
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 22, filter: 'blur(12px)' }}
      animate={{ opacity: 1, y: 0, filter: 'blur(0px)' }}
      transition={{ duration: 1.1, ease: REVEAL_EASE, delay }}
    >
      {children}
    </motion.div>
  )
}

/** `init_` wipes in left-to-right, then the underscore blinks like a caret. */
function WordmarkReveal({
  delay,
  reduceMotion,
}: {
  delay: number
  reduceMotion: boolean
}) {
  const wordmark = (
    <InitWordmark
      className="text-[clamp(68px,11vw,150px)] text-foreground"
      underscoreClassName={reduceMotion ? undefined : 'init5-caret'}
    />
  )
  if (reduceMotion) return <div className="mt-5 sm:mt-6">{wordmark}</div>
  return (
    <motion.div
      className="mt-5 sm:mt-6"
      style={{ '--init5-caret-delay': `${delay + 0.95}s` } as CSSProperties}
      initial={{
        clipPath: 'inset(-15% 100% -15% -6%)',
        opacity: 0,
        filter: 'blur(10px)',
      }}
      animate={{
        clipPath: 'inset(-15% -8% -15% -6%)',
        opacity: 1,
        filter: 'blur(0px)',
      }}
      transition={{
        clipPath: { duration: 1.15, ease: WIPE_EASE, delay },
        opacity: { duration: 0.35, delay },
        filter: { duration: 0.9, ease: REVEAL_EASE, delay },
      }}
    >
      {wordmark}
    </motion.div>
  )
}

export function Init5HeroContent({
  event,
  headerAddon,
  actions,
  presence,
  reduceMotion,
}: {
  event: InitDisplayEvent
  headerAddon?: ReactNode
  actions?: ReactNode
  presence?: ReactNode
  reduceMotion: boolean
}) {
  const { replayToken, showSwitcher } = useInit5Hero()
  const showCountdown = !event.isRecapMode && event.currentDay <= 0
  const anchorProps = { [INIT5_HERO_ANCHOR_ATTRIBUTE]: '' }

  return (
    <div className="relative z-10 mx-auto grid w-full max-w-7xl grid-cols-1 items-center px-4 sm:px-6 lg:min-h-[inherit] lg:grid-cols-[minmax(0,1.02fr)_minmax(0,0.98fr)] lg:gap-10">
      {/* The WebGL 5 is drawn by the stage behind this box. */}
      <div
        {...anchorProps}
        aria-hidden
        className="pointer-events-none mx-auto mt-6 h-[clamp(260px,78vw,400px)] w-full max-w-[540px] sm:mt-10 sm:h-[420px] lg:order-2 lg:mx-0 lg:mt-0 lg:h-[clamp(460px,68svh,660px)] lg:max-w-none"
      />

      <div
        key={replayToken}
        className={cn(
          'flex flex-col items-center pb-16 text-center sm:pb-20',
          'lg:order-1 lg:items-start lg:py-24 lg:text-start',
          showSwitcher && 'pb-28 sm:pb-28 lg:pb-28',
        )}
      >
        {headerAddon}
        <Reveal
          delay={0.18}
          reduceMotion={reduceMotion}
          className={cn(headerAddon && 'mt-4')}
        >
          <p className="flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[0.32em] text-foreground/85 sm:text-[12px]">
            <span
              aria-hidden
              className="h-px w-8 bg-gradient-to-r from-transparent to-[var(--brand-cta)] sm:w-10"
            />
            {event.dateRangeLabel}
          </p>
        </Reveal>

        <WordmarkReveal delay={0.34} reduceMotion={reduceMotion} />

        <Reveal delay={0.62} reduceMotion={reduceMotion}>
          <p className="mx-auto mt-5 max-w-[34rem] text-[15px] leading-relaxed text-muted-foreground sm:text-[16px] lg:mx-0">
            {event.description}
          </p>
        </Reveal>

        {actions ? (
          <Reveal delay={0.8} reduceMotion={reduceMotion}>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2.5 lg:justify-start">
              {actions}
            </div>
          </Reveal>
        ) : null}

        {showCountdown ? (
          <Reveal delay={0.98} reduceMotion={reduceMotion}>
            <div className="mt-10">
              <Init5HeroCountdown
                startDate={event.startDate}
                reduceMotion={reduceMotion}
              />
            </div>
          </Reveal>
        ) : null}

        {presence}
      </div>

      {showSwitcher ? <Init5HeroVariantSwitcher /> : null}
    </div>
  )
}
