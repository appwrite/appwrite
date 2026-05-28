import { useCallback, useEffect, useRef, useState } from 'react'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import {
  useInitPresence,
  useInitPresenceActivity,
} from '@/lib/init/init-presence-context'
import { buildInitReactingActivity } from '@/lib/init/reactions'
import {
  INIT_REACTIONS,
  INIT_REACTION_DURATION_MS,
  readStoredInitReaction,
  writeStoredInitReaction,
} from '@/lib/init/reactions'
import { cn } from '@/lib/utils'
import { motion, useReducedMotion } from 'motion/react'

const REACTION_BUTTON_EASE: [number, number, number, number] = [0.34, 1.56, 0.64, 1]

interface InitPresenceReactionsProps {
  eventId: string
  collapsed?: boolean
  isMobile?: boolean
  onReactionPulse?: () => void
}

const REACTIONS_SECTION_CLASS =
  'shrink-0 border-t border-border bg-background px-3 py-2 sm:px-3'

function InitPresenceReactionsSkeleton({
  isMobile = false,
}: {
  isMobile?: boolean
}) {
  return (
    <div
      className={cn(REACTIONS_SECTION_CLASS, isMobile && 'px-4')}
      aria-busy="true"
      aria-label="Loading reactions"
    >
      <div className="grid w-full grid-cols-6 items-center gap-0">
        {INIT_REACTIONS.map((reaction) => (
          <div key={reaction.id} className="flex justify-center">
            <Skeleton className="size-7 rounded-md bg-muted/80 dark:bg-muted/40" />
          </div>
        ))}
      </div>
    </div>
  )
}

function ReactionButton({
  reaction,
  disabled,
  burstKey,
  onClick,
}: {
  reaction: (typeof INIT_REACTIONS)[number]
  disabled: boolean
  burstKey: number
  onClick: () => void
}) {
  const reduceMotion = useReducedMotion()
  const Icon = reaction.icon

  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      disabled={disabled}
      className="relative size-7 shrink-0 overflow-visible rounded-md text-muted-foreground"
      aria-label={reaction.label}
      onClick={onClick}
    >
      <motion.span
        key={burstKey}
        className="inline-flex"
        initial={reduceMotion ? false : { scale: 1, rotate: 0 }}
        animate={
          reduceMotion || burstKey === 0
            ? undefined
            : {
                scale: [1, 1.45, 0.88, 1.12, 1],
                rotate: [0, -16, 14, -8, 0],
              }
        }
        transition={{ duration: 0.45, ease: REACTION_BUTTON_EASE }}
      >
        <Icon className="size-3.5" aria-hidden />
      </motion.span>
      {!reduceMotion && burstKey > 0 ? (
        <motion.span
          key={`ring-${burstKey}`}
          className="pointer-events-none absolute inset-0 rounded-md ring-2 ring-[var(--brand-cta)]"
          initial={{ scale: 0.85, opacity: 0.7 }}
          animate={{ scale: 1.55, opacity: 0 }}
          transition={{ duration: 0.45, ease: 'easeOut' }}
          aria-hidden
        />
      ) : null}
    </Button>
  )
}

export function InitPresenceReactions({
  eventId,
  collapsed = false,
  isMobile = false,
  onReactionPulse,
}: InitPresenceReactionsProps) {
  const { isReady, participantStatus } = useInitPresence()
  const { setTransientActivity } = useInitPresenceActivity()
  const [burstKeys, setBurstKeys] = useState<Record<string, number>>({})
  const resetTimeoutRef = useRef<number | null>(null)
  const eventIdRef = useRef(eventId)
  eventIdRef.current = eventId

  const clearReaction = useCallback(() => {
    if (resetTimeoutRef.current) {
      window.clearTimeout(resetTimeoutRef.current)
      resetTimeoutRef.current = null
    }
    writeStoredInitReaction(eventIdRef.current, null)
    setTransientActivity(null)
  }, [setTransientActivity])

  const scheduleReactionReset = useCallback(
    (delay = INIT_REACTION_DURATION_MS) => {
      if (resetTimeoutRef.current) {
        window.clearTimeout(resetTimeoutRef.current)
      }
      resetTimeoutRef.current = window.setTimeout(() => {
        resetTimeoutRef.current = null
        clearReaction()
      }, delay)
    },
    [clearReaction],
  )

  const applyReaction = useCallback(
    (reactionId: string) => {
      writeStoredInitReaction(eventIdRef.current, reactionId)
      setTransientActivity(buildInitReactingActivity(reactionId))
      scheduleReactionReset(INIT_REACTION_DURATION_MS)
    },
    [scheduleReactionReset, setTransientActivity],
  )

  useEffect(() => {
    const stored = readStoredInitReaction(eventId)
    if (!stored) return

    setTransientActivity(buildInitReactingActivity(stored.reactionId))
    scheduleReactionReset(Math.max(0, stored.expiresAt - Date.now()))

    return () => {
      if (resetTimeoutRef.current) {
        window.clearTimeout(resetTimeoutRef.current)
        resetTimeoutRef.current = null
      }
    }
  }, [eventId, scheduleReactionReset, setTransientActivity])

  if (collapsed && !isMobile) return null
  if (!isReady) {
    return <InitPresenceReactionsSkeleton isMobile={isMobile} />
  }

  const isOnline = participantStatus === 'online'

  const handleReaction = (reactionId: string) => {
    onReactionPulse?.()
    setBurstKeys((previous) => ({
      ...previous,
      [reactionId]: (previous[reactionId] ?? 0) + 1,
    }))
    applyReaction(reactionId)
  }

  return (
    <div
      className={cn(REACTIONS_SECTION_CLASS, isMobile && 'px-4')}
    >
      <div
        className="grid w-full grid-cols-6 items-center gap-0"
        role="group"
        aria-label="React to Init"
      >
        {INIT_REACTIONS.map((reaction) => (
          <div key={reaction.id} className="flex justify-center">
            <ReactionButton
              reaction={reaction}
              disabled={!isOnline}
              burstKey={burstKeys[reaction.id] ?? 0}
              onClick={() => handleReaction(reaction.id)}
            />
          </div>
        ))}
      </div>
    </div>
  )
}
