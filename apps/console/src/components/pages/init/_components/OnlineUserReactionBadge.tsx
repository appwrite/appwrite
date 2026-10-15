import type { InitReaction } from '@/lib/init/reactions'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'
import {
  INIT_PRESENCE_REACTION_SPARK_COUNT,
  InitPresenceReactionSpark,
} from './InitPresenceReactionSpark'

const REACTION_ICON_POP_TRANSITION = {
  duration: 0.5,
  ease: [0.34, 1.56, 0.64, 1] as [number, number, number, number],
}
const REACTION_ICON_IDLE_TRANSITION = {
  duration: 0.55,
  ease: [0.22, 1, 0.36, 1] as [number, number, number, number],
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
      className="reaction-badge relative ms-auto inline-flex size-5 shrink-0 items-center justify-center text-muted-foreground"
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
          {Array.from({ length: INIT_PRESENCE_REACTION_SPARK_COUNT }, (_, index) => (
            <InitPresenceReactionSpark key={index} index={index} animationKey={animationKey} />
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
    <AnimatePresence initial={false}>
      {reaction && animationKey ? (
        <OnlineUserReactionBadge reaction={reaction} animationKey={animationKey} />
      ) : null}
    </AnimatePresence>
  )
}
