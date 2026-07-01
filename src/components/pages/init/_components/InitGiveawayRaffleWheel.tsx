import type { LaunchEventOnlineUser } from '@/lib/init/types'
import { cn } from '@/lib/utils'
import { motion, useReducedMotion } from 'motion/react'
import { useId, useMemo } from 'react'

export const INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE = 360
const WHEEL_SIZE = INIT_GIVEAWAY_RAFFLE_WHEEL_SIZE
const WHEEL_PADDING = 6
const WHEEL_RADIUS = WHEEL_SIZE / 2 - WHEEL_PADDING
const SPIN_DURATION = 4.2
const SPIN_EASE: [number, number, number, number] = [0.12, 0.82, 0.18, 1]

const SEGMENT_FILLS = [
  'color-mix(in srgb, var(--brand-cta) 18%, var(--card))',
  'color-mix(in srgb, var(--brand-cta) 8%, var(--muted))',
] as const

function polarToCartesian(angleDeg: number, radius: number) {
  const angleRad = ((angleDeg - 90) * Math.PI) / 180
  return {
    x: radius * Math.cos(angleRad),
    y: radius * Math.sin(angleRad),
  }
}

function buildSegmentPath(index: number, count: number, radius: number) {
  const segmentAngle = 360 / count
  const startAngle = index * segmentAngle
  const endAngle = (index + 1) * segmentAngle
  const start = polarToCartesian(startAngle, radius)
  const end = polarToCartesian(endAngle, radius)
  const largeArc = segmentAngle > 180 ? 1 : 0

  return [
    'M 0 0',
    `L ${start.x} ${start.y}`,
    `A ${radius} ${radius} 0 ${largeArc} 1 ${end.x} ${end.y}`,
    'Z',
  ].join(' ')
}

function firstName(name: string) {
  const token = name.trim().split(/\s+/)[0] || name
  return token.charAt(0).toUpperCase() + token.slice(1)
}

export function computeRaffleWheelRotation(
  winnerIndex: number,
  participantCount: number,
  currentRotation: number,
): number {
  if (participantCount <= 0) return currentRotation

  const segmentAngle = 360 / participantCount
  const segmentCenter = (winnerIndex + 0.5) * segmentAngle
  const normalized = ((currentRotation % 360) + 360) % 360
  const delta = (360 - segmentCenter - normalized + 360) % 360
  return currentRotation + 360 * 6 + delta
}

interface InitGiveawayRaffleWheelProps {
  participants: LaunchEventOnlineUser[]
  rotation: number
  className?: string
}

export function InitGiveawayRaffleWheel({
  participants,
  rotation,
  className,
}: InitGiveawayRaffleWheelProps) {
  const reduceMotion = useReducedMotion()
  const clipId = `raffle-wheel-${useId().replace(/:/g, '')}`
  const count = participants.length
  const center = WHEEL_SIZE / 2

  const segments = useMemo(() => {
    if (count === 0) return []

    const segmentAngle = 360 / count
    const labelRadius = WHEEL_RADIUS * 0.58

    return participants.map((participant, index) => {
      const midAngle = index * segmentAngle + segmentAngle / 2
      const labelPosition = polarToCartesian(midAngle, labelRadius)

      return {
        participant,
        path: buildSegmentPath(index, count, WHEEL_RADIUS),
        fill: SEGMENT_FILLS[index % SEGMENT_FILLS.length],
        labelPosition,
        labelRotation: midAngle,
      }
    })
  }, [count, participants])

  if (count === 0) {
    return (
      <div
        className={cn(
          'flex items-center justify-center rounded-full border border-dashed border-border bg-muted/20',
          className,
        )}
        style={{ width: WHEEL_SIZE, height: WHEEL_SIZE }}
      >
        <p className="max-w-[200px] text-center text-[13px] text-muted-foreground">
          No one is online right now.
        </p>
      </div>
    )
  }

  return (
    <div
      className={cn('relative shrink-0', className)}
      style={{ width: WHEEL_SIZE, height: WHEEL_SIZE }}
    >
      <div
        className="pointer-events-none absolute start-1/2 top-0 z-20 -translate-x-1/2"
        aria-hidden
      >
        <div className="size-0 border-x-[11px] border-x-transparent border-t-[18px] border-t-[var(--brand-cta)] drop-shadow-sm" />
      </div>

      <div className="absolute inset-[10px] overflow-hidden rounded-full border border-border bg-card shadow-lg">
        <motion.div
          className="size-full origin-center"
          animate={{ rotate: rotation }}
          transition={
            reduceMotion
              ? { duration: 0 }
              : { duration: SPIN_DURATION, ease: SPIN_EASE }
          }
        >
          <svg
            width={WHEEL_SIZE}
            height={WHEEL_SIZE}
            viewBox={`0 0 ${WHEEL_SIZE} ${WHEEL_SIZE}`}
            className="size-full"
            role="img"
            aria-label="Giveaway raffle wheel"
          >
            <defs>
              <clipPath id={clipId}>
                <circle cx={center} cy={center} r={WHEEL_RADIUS} />
              </clipPath>
            </defs>
            <g clipPath={`url(#${clipId})`}>
              <g transform={`translate(${center}, ${center})`}>
                {segments.map(({ participant, path, fill, labelPosition, labelRotation }) => (
                  <g key={participant.id}>
                    <path d={path} fill={fill} stroke="var(--border)" strokeWidth={1} />
                    <text
                      x={labelPosition.x}
                      y={labelPosition.y}
                      fill="var(--foreground)"
                      fontSize={count > 12 ? 9 : count > 6 ? 10 : 12}
                      fontWeight={600}
                      textAnchor="middle"
                      dominantBaseline="middle"
                      transform={`rotate(${labelRotation}, ${labelPosition.x}, ${labelPosition.y})`}
                    >
                      {firstName(participant.name).slice(0, count > 14 ? 6 : 12)}
                    </text>
                  </g>
                ))}
              </g>
            </g>
          </svg>
        </motion.div>
      </div>

      <div
        className="pointer-events-none absolute start-1/2 top-1/2 z-10 size-11 -translate-x-1/2 -translate-y-1/2 rounded-full border border-border bg-card shadow-sm"
        aria-hidden
      />
    </div>
  )
}

export const INIT_GIVEAWAY_RAFFLE_SPIN_MS = SPIN_DURATION * 1000
