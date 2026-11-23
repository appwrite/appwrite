import { useEffect, useMemo, useRef, useState } from 'react'
import type { LaunchEventOnlineUser } from '@/lib/init/types'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  getInitReactionById,
  getInitReactionCounts,
  INIT_REACTION_CONFETTI_COOLDOWN_MS,
  INIT_REACTION_CONFETTI_THRESHOLD,
} from '@/lib/init/reactions'
import { AnimatePresence, motion, useReducedMotion } from 'motion/react'

const CONFETTI_PARTICLE_COUNT = 52
const CONFETTI_EASE: [number, number, number, number] = [0.22, 1, 0.36, 1]
const CONFETTI_EVAL_INTERVAL_MS = 1_000

type ConfettiBurst = {
  reactionId: string
  key: number
}

type ConfettiParticle = {
  id: number
  x: number
  y: number
  size: number
  delay: number
  duration: number
  spin: number
}

function createConfettiParticles(burstKey: number): ConfettiParticle[] {
  return Array.from({ length: CONFETTI_PARTICLE_COUNT }, (_, index) => {
    const seed = burstKey + index * 997
    const rand = (offset: number) => {
      const value = Math.sin(seed + offset * 12.9898) * 43758.5453
      return value - Math.floor(value)
    }

    const angle = Math.PI / 12 + rand(1) * (Math.PI * 0.48)
    const distance = 180 + rand(2) * 420
    const wobble = (rand(3) - 0.5) * 140

    return {
      id: index,
      x: Math.cos(angle) * distance + wobble,
      y: -Math.sin(angle) * distance - rand(4) * 90,
      size: 12 + rand(5) * 16,
      delay: rand(6) * 0.14,
      duration: 1.05 + rand(7) * 0.85,
      spin: (rand(8) - 0.5) * 540,
    }
  })
}

function ReactionConfettiBurst({
  reactionId,
  burstKey,
  onComplete,
}: {
  reactionId: string
  burstKey: number
  onComplete: () => void
}) {
  const reduceMotion = useReducedMotion()
  const reaction = getInitReactionById(reactionId)
  const particles = useMemo(() => createConfettiParticles(burstKey), [burstKey])

  useEffect(() => {
    if (reduceMotion || !reaction) {
      onComplete()
    }
  }, [onComplete, reaction, reduceMotion])

  if (!reaction || reduceMotion) return null

  const Icon = reaction.icon

  return (
    <div
      className="pointer-events-none fixed bottom-0 start-0 z-[120] h-0 w-0 overflow-visible"
      aria-hidden
    >
      {particles.map((particle, index) => (
        <motion.span
          key={`${burstKey}-${particle.id}`}
          className="absolute text-muted-foreground"
          initial={{ x: 0, y: 0, opacity: 0, scale: 0, rotate: 0 }}
          animate={{
            x: particle.x,
            y: particle.y,
            opacity: [0, 1, 1, 0.85, 0],
            scale: [0, 1.25, 1, 0.85, 0.5],
            rotate: particle.spin,
          }}
          transition={{
            duration: particle.duration,
            delay: particle.delay,
            ease: CONFETTI_EASE,
          }}
          onAnimationComplete={
            index === particles.length - 1 ? onComplete : undefined
          }
        >
          <Icon style={{ width: particle.size, height: particle.size }} aria-hidden />
        </motion.span>
      ))}
    </div>
  )
}

function evaluateConfettiBurst(options: {
  onlineUsers: LaunchEventOnlineUser[]
  confettiThreshold: number
  previousCounts: Map<string, number>
  lastBurstAt: Map<string, number>
  now?: number
}): { reactionId: string; key: number } | null {
  const now = options.now ?? Date.now()
  const counts = getInitReactionCounts(options.onlineUsers)

  for (const reactionId of options.lastBurstAt.keys()) {
    if ((counts.get(reactionId) ?? 0) < options.confettiThreshold) {
      options.lastBurstAt.delete(reactionId)
    }
  }

  for (const [reactionId, count] of counts) {
    if (count < options.confettiThreshold) continue

    const lastBurst = options.lastBurstAt.get(reactionId) ?? 0
    if (now - lastBurst < INIT_REACTION_CONFETTI_COOLDOWN_MS) continue

    const previous = options.previousCounts.get(reactionId) ?? 0
    const crossedThreshold = previous < options.confettiThreshold
    const gainedParticipants = count > previous
    const cooldownElapsed = lastBurst > 0

    if (crossedThreshold || gainedParticipants || cooldownElapsed) {
      options.lastBurstAt.set(reactionId, now)
      options.previousCounts.clear()
      for (const [id, value] of counts) {
        options.previousCounts.set(id, value)
      }
      return { reactionId, key: now }
    }
  }

  options.previousCounts.clear()
  for (const [id, value] of counts) {
    options.previousCounts.set(id, value)
  }

  return null
}

export function InitReactionConfetti({
  onlineUsers,
}: {
  onlineUsers: LaunchEventOnlineUser[]
}) {
  const reduceMotion = useReducedMotion()
  const { previewInitReactionConfetti } = useDebugOverrides()
  const [burst, setBurst] = useState<ConfettiBurst | null>(null)
  const previousCountsRef = useRef<Map<string, number>>(new Map())
  const lastBurstAtRef = useRef<Map<string, number>>(new Map())
  const onlineUsersRef = useRef(onlineUsers)
  onlineUsersRef.current = onlineUsers

  const confettiThreshold = previewInitReactionConfetti
    ? 1
    : INIT_REACTION_CONFETTI_THRESHOLD

  useEffect(() => {
    previousCountsRef.current = new Map()
    lastBurstAtRef.current = new Map()
  }, [previewInitReactionConfetti])

  useEffect(() => {
    if (reduceMotion) return undefined

    const runEvaluation = () => {
      const nextBurst = evaluateConfettiBurst({
        onlineUsers: onlineUsersRef.current,
        confettiThreshold,
        previousCounts: previousCountsRef.current,
        lastBurstAt: lastBurstAtRef.current,
      })
      if (nextBurst) {
        setBurst(nextBurst)
      }
    }

    runEvaluation()
    const interval = window.setInterval(runEvaluation, CONFETTI_EVAL_INTERVAL_MS)
    return () => window.clearInterval(interval)
  }, [confettiThreshold, onlineUsers, reduceMotion])

  return (
    <AnimatePresence>
      {burst ? (
        <ReactionConfettiBurst
          key={burst.key}
          reactionId={burst.reactionId}
          burstKey={burst.key}
          onComplete={() => setBurst(null)}
        />
      ) : null}
    </AnimatePresence>
  )
}
