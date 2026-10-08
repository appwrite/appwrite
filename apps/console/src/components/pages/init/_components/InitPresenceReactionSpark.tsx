import { cn } from '@/lib/utils'
import { motion } from 'motion/react'

export const INIT_PRESENCE_REACTION_SPARK_COUNT = 6

export function InitPresenceReactionSpark({
  index,
  animationKey,
  sparkCount = INIT_PRESENCE_REACTION_SPARK_COUNT,
}: {
  index: number
  animationKey: string
  sparkCount?: number
}) {
  const phase = (index / sparkCount) * Math.PI * 2
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
        'absolute start-1/2 top-1/2 size-1 -translate-x-1/2 -translate-y-1/2 rounded-full',
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
