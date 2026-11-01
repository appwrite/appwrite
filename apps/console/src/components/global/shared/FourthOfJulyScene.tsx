import { useEffect, useRef, useState } from 'react'

/** Below this much free space the scene would crowd the nav, so it stays hidden. */
const MIN_HEIGHT = 140
const MIN_HEIGHT_COMPACT = 120

const STARS = [
  [16, 20, 0],
  [48, 8, 1.2],
  [84, 26, 0.5],
  [122, 10, 1.8],
  [158, 22, 0.9],
  [194, 12, 2.1],
  [32, 46, 1.4],
  [104, 38, 0.6],
] as const

function Star({ cx, cy, delay }: { cx: number; cy: number; delay: number }) {
  return (
    <circle
      className="july-twinkle"
      style={{ animationDelay: `${delay}s` }}
      cx={cx}
      cy={cy}
      r="1.1"
      fill="#F8FAFC"
      fillOpacity="0.75"
    />
  )
}

function Burst({
  x,
  y,
  color,
  delay,
  scale = 1,
}: {
  x: number
  y: number
  color: string
  delay: string
  scale?: number
}) {
  return (
    <g
      className="july-burst"
      style={{ animationDelay: delay }}
      transform={`translate(${x} ${y}) scale(${scale})`}
    >
      {[0, 45, 90, 135, 180, 225, 270, 315].map((angle) => (
        <line
          key={angle}
          x1="0"
          y1="-3"
          x2="0"
          y2="-14"
          stroke={color}
          strokeWidth="1.3"
          strokeLinecap="round"
          transform={`rotate(${angle})`}
        />
      ))}
      <circle r="2.2" fill={color} fillOpacity="0.85" />
    </g>
  )
}

function Flag({ x, y, scale = 1 }: { x: number; y: number; scale?: number }) {
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <rect x="-0.8" y="-28" width="1.6" height="36" rx="0.5" fill="#7C4A1E" />
      <rect x="0.8" y="-28" width="22" height="14" fill="#0A3161" />
      <rect x="0.8" y="-14" width="22" height="2" fill="#B91C1C" />
      <rect x="0.8" y="-12" width="22" height="2" fill="#F8FAFC" />
      <rect x="0.8" y="-10" width="22" height="2" fill="#B91C1C" />
      <rect x="0.8" y="-8" width="22" height="2" fill="#F8FAFC" />
      <g fill="#F8FAFC">
        <circle cx="5" cy="-24" r="0.7" />
        <circle cx="9" cy="-24" r="0.7" />
        <circle cx="13" cy="-24" r="0.7" />
        <circle cx="7" cy="-21.2" r="0.7" />
        <circle cx="11" cy="-21.2" r="0.7" />
      </g>
    </g>
  )
}

function FullScene() {
  return (
    <svg
      viewBox="0 0 220 180"
      className="july-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      {STARS.map(([cx, cy, delay]) => (
        <Star key={`${cx}-${cy}`} cx={cx} cy={cy} delay={delay} />
      ))}

      <Burst x={58} y={52} color="#B91C1C" delay="0s" />
      <Burst x={168} y={40} color="#60A5FA" delay="-1.2s" scale={0.85} />
      <Burst x={118} y={72} color="#F8FAFC" delay="-2.1s" scale={0.65} />

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

      <Flag x={46} y={164} />
      <Flag x={168} y={168} scale={0.72} />
    </svg>
  )
}

function CompactScene() {
  return (
    <svg
      viewBox="0 0 60 170"
      overflow="visible"
      className="july-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Burst x={30} y={36} color="#B91C1C" delay="0s" scale={0.7} />
      <Burst x={18} y={58} color="#60A5FA" delay="-1.4s" scale={0.5} />
      <path
        d="M0 150c15-8 45-8 60 0v20H0Z"
        fill="currentColor"
        fillOpacity="0.08"
      />
      <Flag x={18} y={164} scale={0.85} />
    </svg>
  )
}

/**
 * Fireworks and flags that fill the free sidebar space above Settings, or a
 * narrow strip on the collapsed rail. Parent must be a flex column; the scene
 * only takes space nothing else needs.
 */
export function FourthOfJulyScene({ compact = false }: { compact?: boolean }) {
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
      {roomy && (compact ? <CompactScene /> : <FullScene />)}
    </div>
  )
}
