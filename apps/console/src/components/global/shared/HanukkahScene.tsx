import { useEffect, useRef, useState } from 'react'
import { Hanukkiah } from '@/components/global/shared/HanukkahMenorah'

/** Below this much free space the scene would crowd the nav, so it stays hidden. */
const MIN_HEIGHT = 140
const MIN_HEIGHT_COMPACT = 120

const SPARKS = [
  [18, 22, 0],
  [52, 10, 1.1],
  [88, 28, 0.4],
  [124, 8, 1.7],
  [162, 20, 0.8],
  [196, 14, 2],
  [36, 48, 1.4],
  [108, 40, 0.6],
] as const

function Spark({ cx, cy, delay }: { cx: number; cy: number; delay: number }) {
  return (
    <circle
      className="hanukkah-twinkle"
      style={{ animationDelay: `${delay}s` }}
      cx={cx}
      cy={cy}
      r="1.15"
      fill="#E8C547"
      fillOpacity="0.7"
    />
  )
}

function StarOfDavid({
  x,
  y,
  scale = 1,
}: {
  x: number
  y: number
  scale?: number
}) {
  return (
    <g
      className="hanukkah-glow"
      transform={`translate(${x} ${y}) scale(${scale})`}
      fill="none"
      stroke="#60A5FA"
      strokeOpacity="0.7"
      strokeWidth="1.1"
    >
      <path d="M0-7 6 4H-6Z" />
      <path d="M0 7 6-4H-6Z" />
    </g>
  )
}

function Gelt({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <ellipse rx="5.5" ry="5" fill="#E8C547" />
      <ellipse rx="3.6" ry="3.2" fill="#FDE68A" fillOpacity="0.7" />
    </g>
  )
}

function OilJug({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <path
        d="M-5 2c0-5 2-8 5-8s5 3 5 8c0 3-2 5-5 5s-5-2-5-5Z"
        fill="#2563EB"
      />
      <path
        d="M5-3c4-1 6 2 4 5"
        fill="none"
        stroke="#1D4ED8"
        strokeWidth="1.6"
      />
      <rect x="-1.4" y="-10" width="2.8" height="3" rx="0.6" fill="#C4930A" />
    </g>
  )
}

function FullScene({ night }: { night: number }) {
  return (
    <svg
      viewBox="0 0 220 180"
      className="hanukkah-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      {SPARKS.map(([cx, cy, delay]) => (
        <Spark key={`${cx}-${cy}`} cx={cx} cy={cy} delay={delay} />
      ))}
      <StarOfDavid x={36} y={36} scale={0.9} />
      <StarOfDavid x={188} y={48} scale={0.7} />

      <path
        d="M0 150C40 136 86 132 124 142c28 8 56-2 96-10v48H0Z"
        fill="currentColor"
        fillOpacity="0.05"
      />
      <path
        d="M0 162c36-10 74-12 112-4 28 6 58-2 108-6v28H0Z"
        fill="currentColor"
        fillOpacity="0.08"
      />

      <g transform="translate(18 62) scale(2.15)">
        <Hanukkiah night={night} />
      </g>

      <OilJug x={28} y={160} />
      <Gelt x={48} y={168} />
      <Gelt x={60} y={166} />
      <Gelt x={178} y={168} />
    </svg>
  )
}

function CompactScene({ night }: { night: number }) {
  return (
    <svg
      viewBox="0 0 60 170"
      overflow="visible"
      className="hanukkah-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <StarOfDavid x={30} y={28} scale={0.7} />
      <path
        d="M0 150c15-8 45-8 60 0v20H0Z"
        fill="currentColor"
        fillOpacity="0.08"
      />
      <g transform="translate(-12 72) scale(1.2)">
        <Hanukkiah night={night} />
      </g>
    </svg>
  )
}

/**
 * Hanukkiah that fills the free sidebar space above Settings, or a narrow
 * strip on the collapsed rail. Parent must be a flex column; the scene only
 * takes space nothing else needs.
 */
export function HanukkahScene({
  compact = false,
  night,
}: {
  compact?: boolean
  night: number
}) {
  const ref = useRef<HTMLDivElement>(null)
  const [height, setHeight] = useState(0)

  useEffect(() => {
    const element = ref.current
    if (!element) return
    const observer = new ResizeObserver(([entry]) => {
      setHeight(entry.contentRect.height)
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  const roomy = height >= (compact ? MIN_HEIGHT_COMPACT : MIN_HEIGHT)

  return (
    <div
      ref={ref}
      className="pointer-events-none relative -mx-3 -mb-4 min-h-0 flex-1 basis-0 overflow-hidden [mask-image:linear-gradient(to_bottom,transparent,black_30%)]"
      aria-hidden
    >
      {roomy &&
        (compact ? (
          <CompactScene night={night} />
        ) : (
          <FullScene night={night} />
        ))}
    </div>
  )
}
