import type { InitReaction } from '@/lib/init/reactions'
import { cn } from '@/lib/utils'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'

const REACTION_ICON_POP_TRANSITION = {
  duration: 0.5,
  ease: [0.34, 1.56, 0.64, 1] as [number, number, number, number],
}
const REACTION_ICON_IDLE_TRANSITION = {
  duration: 0.55,
  ease: [0.22, 1, 0.36, 1] as [number, number, number, number],
}

const SPARK_COUNT = 6

function ReactionSpark({
  index,
  animationKey,
}: {
  index: number
  animationKey: string
}) {
  const phase = (index / SPARK_COUNT) * Math.PI * 2
  const radius = 8 + (index % 3) * 2.5
  const steps = 5
  const xKeyframes = Array.from({ length: steps }, (_, step) => {
    const angle = phase + (step / (steps - 1)) * Math.PI * 2
    return Math.cos(angle) * radius
  })
  const yKeyframes = Array.from({ length: steps }, (_, step) => {
    const angle = phase + (step / (steps - 1)) * Math.PI * 2
    return Math.sin(angle) * radius
  })

  return (
    <motion.span
      key={`${animationKey}-spark-${index}`}
      className={cn(
        'absolute left-1/2 top-1/2 size-1 -translate-x-1/2 -translate-y-1/2 rounded-full',
        'bg-muted-foreground/50 shadow-[0_0_4px_color-mix(in_srgb,var(--muted-foreground)_35%,transparent)]',
        index % 2 === 0 && 'size-[3px] rounded-[1px] rotate-45',
      )}
      initial={{ x: 0, y: 0, opacity: 0, scale: 0 }}
      animate={{
        x: [...xKeyframes, xKeyframes[0]],
        y: [...yKeyframes, yKeyframes[0]],
        opacity: [0.2, 0.75, 0.45, 0.85, 0.35, 0.2],
        scale: [0.4, 1, 0.65, 0.95, 0.5, 0.4],
      }}
      transition={{
        duration: 1.6 + index * 0.12,
        repeat: Infinity,
        ease: 'linear',
        delay: index * 0.08,
      }}
      aria-hidden
    />
  )
}

export function OnlineUserReactionBadge({
  reaction,
  animationKey,
}: {
  reaction: InitReaction
  animationKey: string
}) {
  const reduceMotion = useReducedMotion()
  const Icon = reaction.icon

  return (
    <motion.span
      key={animationKey}
      className="reaction-badge relative ml-auto inline-flex size-5 shrink-0 items-center justify-center text-muted-foreground"
      initial={reduceMotion ? false : { scale: 0, opacity: 0, rotate: -28, y: 3 }}
      animate={
        reduceMotion
          ? { scale: 1, opacity: 1, rotate: 0, y: 0 }
          : {
              scale: [0, 1.35, 0.92, 1.06, 1],
              opacity: [0, 1, 1, 1, 1],
              rotate: [-28, 14, -8, 4, 0],
              y: [3, -2, 1, 0, 0],
            }
      }
      exit={
        reduceMotion
          ? undefined
          : { scale: 0, opacity: 0, rotate: 16, transition: { duration: 0.14 } }
      }
      transition={REACTION_ICON_POP_TRANSITION}
      aria-hidden
    >
      {!reduceMotion ? (
        <span className="pointer-events-none absolute inset-[-6px]" aria-hidden>
          {Array.from({ length: SPARK_COUNT }, (_, index) => (
            <ReactionSpark key={index} index={index} animationKey={animationKey} />
          ))}
        </span>
      ) : null}
      <motion.span
        className="relative z-[1]"
        animate={
          reduceMotion ? undefined : { scale: [1, 1.12, 1], rotate: [0, -6, 6, 0] }
        }
        transition={{
          ...REACTION_ICON_IDLE_TRANSITION,
          repeat: Infinity,
          repeatDelay: 2.2,
        }}
      >
        <Icon className="size-3.5" />
      </motion.span>
    </motion.span>
  )
}

export function OnlineUserReactionBadgeSlot({
  reaction,
  animationKey,
}: {
  reaction: InitReaction | null
  animationKey: string | null
}) {
  return (
    <AnimatePresence initial={false} mode="popLayout">
      {reaction && animationKey ? (
        <OnlineUserReactionBadge reaction={reaction} animationKey={animationKey} />
      ) : null}
    </AnimatePresence>
  )
}
