import { useEffect, useMemo, useState } from 'react'
import { motion, useReducedMotion } from 'motion/react'

const CONFETTI_PARTICLE_COUNT = 72
const CONFETTI_EASE: [number, number, number, number] = [0.22, 1, 0.36, 1]
const CONFETTI_COLORS = [
  'color-mix(in srgb, var(--foreground) 70%, transparent)',
  'color-mix(in srgb, var(--foreground) 45%, transparent)',
  'color-mix(in srgb, var(--muted-foreground) 80%, transparent)',
  'color-mix(in srgb, var(--foreground) 28%, transparent)',
  'color-mix(in srgb, var(--muted-foreground) 55%, transparent)',
  'color-mix(in srgb, var(--border) 85%, var(--foreground) 15%)',
] as const

type ConfettiParticle = {
  id: number
  x: number
  y: number
  width: number
  height: number
  delay: number
  duration: number
  spin: number
  color: string
  round: boolean
}

function createConfettiParticles(burstKey: number): ConfettiParticle[] {
  return Array.from({ length: CONFETTI_PARTICLE_COUNT }, (_, index) => {
    const seed = burstKey + index * 991
    const rand = (offset: number) => {
      const value = Math.sin(seed + offset * 12.9898) * 43758.5453
      return value - Math.floor(value)
    }

    const angle = rand(1) * Math.PI * 2
    const distance = 120 + rand(2) * 520
    const drift = (rand(3) - 0.5) * 180

    return {
      id: index,
      x: Math.cos(angle) * distance + drift,
      y: Math.sin(angle) * distance * 0.75 + 120 + rand(4) * 280,
      width: 7 + rand(5) * 10,
      height: 4 + rand(6) * 8,
      delay: rand(7) * 0.18,
      duration: 1.2 + rand(8) * 1.1,
      spin: (rand(9) - 0.5) * 720,
      color: CONFETTI_COLORS[index % CONFETTI_COLORS.length],
      round: rand(10) > 0.55,
    }
  })
}

function GiveawayRaffleConfettiBurst({
  burstKey,
  onComplete,
}: {
  burstKey: number
  onComplete: () => void
}) {
  const reduceMotion = useReducedMotion()
  const particles = useMemo(() => createConfettiParticles(burstKey), [burstKey])

  useEffect(() => {
    if (reduceMotion) onComplete()
  }, [onComplete, reduceMotion])

  if (reduceMotion) return null

  return (
    <div
      className="pointer-events-none fixed inset-0 z-[125] overflow-hidden"
      aria-hidden
    >
      <div className="absolute start-1/2 top-[38%] h-0 w-0 -translate-x-1/2 -translate-y-1/2">
        {particles.map((particle, index) => (
          <motion.span
            key={`${burstKey}-${particle.id}`}
            className="absolute block"
            style={{
              width: particle.width,
              height: particle.height,
              backgroundColor: particle.color,
              borderRadius: particle.round ? '9999px' : '2px',
            }}
            initial={{ x: 0, y: 0, opacity: 0, scale: 0, rotate: 0 }}
            animate={{
              x: particle.x,
              y: particle.y,
              opacity: [0, 1, 1, 0.85, 0],
              scale: [0, 1.15, 1, 0.9, 0.45],
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
          />
        ))}
      </div>
    </div>
  )
}

export function InitGiveawayRaffleConfetti({ pulse }: { pulse: number }) {
  const reduceMotion = useReducedMotion()
  const [burstKey, setBurstKey] = useState<number | null>(null)

  useEffect(() => {
    if (reduceMotion || pulse <= 0) return
    setBurstKey(pulse)
  }, [pulse, reduceMotion])

  if (!burstKey) return null

  return (
    <GiveawayRaffleConfettiBurst
      burstKey={burstKey}
      onComplete={() => setBurstKey(null)}
    />
  )
}
