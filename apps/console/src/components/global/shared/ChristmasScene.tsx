import { useEffect, useRef, useState } from 'react'

/** Below this much free space the scene would crowd the nav, so it stays hidden. */
const MIN_HEIGHT = 140
const MIN_HEIGHT_COMPACT = 120

const SNOW = [
  [18, 22, 0],
  [46, 10, 1.2],
  [78, 28, 0.4],
  [112, 8, 1.8],
  [148, 20, 0.9],
  [188, 14, 2.1],
  [34, 48, 1.5],
  [96, 40, 0.6],
  [168, 52, 1.1],
  [204, 36, 0.3],
] as const

const SNOW_COMPACT = [
  [10, 16, 0],
  [28, 8, 1.1],
  [46, 22, 0.5],
  [18, 40, 1.6],
  [50, 48, 0.8],
] as const

function Snowflake({
  cx,
  cy,
  delay,
}: {
  cx: number
  cy: number
  delay: number
}) {
  return (
    <circle
      className="christmas-fall"
      style={{ animationDelay: `${delay}s` }}
      cx={cx}
      cy={cy}
      r="1.1"
      fill="currentColor"
      fillOpacity="0.45"
    />
  )
}

function Star({ x, y, scale = 1 }: { x: number; y: number; scale?: number }) {
  return (
    <g
      className="christmas-glow"
      transform={`translate(${x} ${y}) scale(${scale})`}
    >
      <path
        d="M0-7 1.8-2.2 7-1.6 3.1 1.7 4.3 7 0 4.2-4.3 7-3.1 1.7-7-1.6-1.8-2.2Z"
        fill="#FBBF24"
      />
    </g>
  )
}

function Tree({ x, y, scale = 1 }: { x: number; y: number; scale?: number }) {
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <rect x="-2" y="22" width="4" height="10" rx="0.8" fill="#7C4A1E" />
      <path d="M0-18 18 8H-18Z" fill="#166534" />
      <path d="M0-8 16 16H-16Z" fill="#15803D" />
      <path d="M0 4 14 26H-14Z" fill="#166534" />
      <circle
        className="christmas-twinkle"
        cx="-6"
        cy="2"
        r="1.4"
        fill="#DC2626"
      />
      <circle
        className="christmas-twinkle"
        cx="7"
        cy="8"
        r="1.4"
        fill="#EAB308"
        style={{ animationDelay: '-0.8s' }}
      />
      <circle
        className="christmas-twinkle"
        cx="-4"
        cy="16"
        r="1.4"
        fill="#F8FAFC"
        style={{ animationDelay: '-1.4s' }}
      />
      <circle
        className="christmas-twinkle"
        cx="5"
        cy="20"
        r="1.4"
        fill="#DC2626"
        style={{ animationDelay: '-2s' }}
      />
      <Star x={0} y={-20} scale={0.7} />
    </g>
  )
}

function Gift({
  x,
  y,
  width,
  height,
  fill,
}: {
  x: number
  y: number
  width: number
  height: number
  fill: string
}) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <rect
        x={-width / 2}
        y={-height}
        width={width}
        height={height}
        rx="1.2"
        fill={fill}
      />
      <rect
        x={-1}
        y={-height}
        width="2"
        height={height}
        fill="#FDE68A"
        fillOpacity="0.9"
      />
      <rect
        x={-width / 2}
        y={-height / 2 - 1}
        width={width}
        height="2"
        fill="#FDE68A"
        fillOpacity="0.9"
      />
    </g>
  )
}

function FullScene() {
  return (
    <svg
      viewBox="0 0 220 180"
      className="christmas-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      {SNOW.map(([cx, cy, delay]) => (
        <Snowflake key={`${cx}-${cy}`} cx={cx} cy={cy} delay={delay} />
      ))}

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

      <Tree x={64} y={128} scale={1.05} />
      <Tree x={168} y={136} scale={0.72} />

      <Gift x={108} y={168} width={16} height={12} fill="#DC2626" />
      <Gift x={126} y={166} width={12} height={10} fill="#166534" />
      <Gift x={38} y={170} width={11} height={9} fill="#B45309" />
    </svg>
  )
}

function CompactScene() {
  return (
    <svg
      viewBox="0 0 60 170"
      overflow="visible"
      className="christmas-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      {SNOW_COMPACT.map(([cx, cy, delay]) => (
        <Snowflake key={`${cx}-${cy}`} cx={cx} cy={cy} delay={delay} />
      ))}
      <path
        d="M0 150c15-8 45-8 60 0v20H0Z"
        fill="currentColor"
        fillOpacity="0.08"
      />
      <Tree x={30} y={118} scale={0.72} />
      <Gift x={30} y={164} width={12} height={9} fill="#DC2626" />
    </svg>
  )
}

/**
 * Snowy trees that fill the free sidebar space above Settings, or a narrow
 * strip of them on the collapsed rail. Parent must be a flex column; the
 * scene only takes space nothing else needs.
 */
export function ChristmasScene({ compact = false }: { compact?: boolean }) {
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
