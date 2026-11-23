import { useEffect, useRef, useState } from 'react'

/** Below this much free space the scene would crowd the nav, so it stays hidden. */
const MIN_HEIGHT = 140
const MIN_HEIGHT_COMPACT = 120

const BAT_PATH =
  'M12 3c-.8 0-1.4.6-1.6 1.3C9 2.8 6.6 2 4 2.6c1.3.8 1.8 2 1.6 3.3C4.4 5.3 2.6 5.4 0 6.6c2.2.3 3.8 1.3 4.6 2.8C6 8.6 7.8 8.8 9 9.8c.5-1.2 1.6-2 3-2s2.5.8 3 2c1.2-1 3-1.2 4.4-.4.8-1.5 2.4-2.5 4.6-2.8-2.6-1.2-4.4-1.3-5.6-.7-.2-1.3.3-2.5 1.6-3.3-2.6-.6-5 .2-6.4 1.7C13.4 3.6 12.8 3 12 3ZM11 3.4l.3-1.6.6 1.2Zm2 0-.3-1.6-.6 1.2Z'

const STARS = [
  [18, 26, 0],
  [52, 12, 1.1],
  [96, 34, 0.5],
  [124, 10, 1.7],
  [206, 74, 0.8],
  [84, 70, 2.2],
  [30, 64, 1.4],
] as const

const STARS_COMPACT = [
  [8, 14, 0],
  [52, 8, 1.3],
  [10, 62, 0.6],
  [54, 58, 2],
] as const

function SceneDefs() {
  return (
    <defs>
      <radialGradient id="halloween-scene-moon-glow">
        <stop offset="0%" stopColor="#FDE68A" stopOpacity="0.45" />
        <stop offset="100%" stopColor="#FDE68A" stopOpacity="0" />
      </radialGradient>
      <radialGradient id="halloween-scene-pumpkin-glow">
        <stop offset="0%" stopColor="#FB923C" stopOpacity="0.55" />
        <stop offset="100%" stopColor="#FB923C" stopOpacity="0" />
      </radialGradient>
    </defs>
  )
}

function Stars({
  stars,
}: {
  stars: ReadonlyArray<readonly [number, number, number]>
}) {
  return stars.map(([cx, cy, delay]) => (
    <circle
      key={`${cx}-${cy}`}
      className="halloween-scene-twinkle"
      style={{ animationDelay: `${delay}s` }}
      cx={cx}
      cy={cy}
      r="1"
      fill="currentColor"
    />
  ))
}

function Moon({ x, y, radius }: { x: number; y: number; radius: number }) {
  const unit = radius / 18
  return (
    <g>
      <circle
        cx={x}
        cy={y}
        r={radius * 2.4}
        fill="url(#halloween-scene-moon-glow)"
      />
      <circle
        cx={x}
        cy={y}
        r={radius}
        fill="#FEF3C7"
        stroke="#FBBF24"
        strokeOpacity="0.4"
      />
      <circle
        cx={x - 6 * unit}
        cy={y - 5 * unit}
        r={3.2 * unit}
        fill="#FDE68A"
      />
      <circle
        cx={x + 6 * unit}
        cy={y + 6 * unit}
        r={2.2 * unit}
        fill="#FDE68A"
      />
      <circle
        cx={x + 4 * unit}
        cy={y - 9 * unit}
        r={1.4 * unit}
        fill="#FDE68A"
      />
    </g>
  )
}

function Bat({
  className,
  x,
  y,
  scale,
}: {
  className: string
  x: number
  y: number
  scale: number
}) {
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <g className={className}>
        <g className="halloween-scene-flap">
          <path
            d={BAT_PATH}
            transform="translate(-12 -6)"
            fill="#18181B"
            stroke="currentColor"
            strokeOpacity="0.3"
            strokeWidth="0.6"
          />
        </g>
      </g>
    </g>
  )
}

export function Spider({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <path
        d="M-3-2.5l-4-3-1-3M-3-.5h-5l-2-2M-3 1.5l-4 3-1 3M3-2.5l4-3 1-3M3-.5h5l2-2M3 1.5l4 3 1 3"
        stroke="currentColor"
        strokeOpacity="0.6"
        strokeWidth="0.7"
        fill="none"
      />
      <ellipse rx="3.2" ry="3.8" fill="currentColor" fillOpacity="0.7" />
    </g>
  )
}

function Pumpkin({
  x,
  y,
  scale,
  delay,
}: {
  x: number
  y: number
  scale: number
  delay: string
}) {
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <circle
        className="halloween-scene-flicker"
        style={{ animationDelay: delay }}
        cy="-8"
        r="22"
        fill="url(#halloween-scene-pumpkin-glow)"
      />
      <path d="M-1.5 -15.5 1.5 -15.5 2.8 -20.5 -0.2 -20.5Z" fill="#4D7C0F" />
      <ellipse cx="-5" cy="-8" rx="6" ry="8" fill="#C2410C" />
      <ellipse cx="5" cy="-8" rx="6" ry="8" fill="#C2410C" />
      <ellipse cy="-8" rx="6" ry="8.5" fill="#EA580C" />
      <path
        className="halloween-scene-flicker"
        style={{ animationDelay: delay }}
        fill="#FDE047"
        d="M-6 -9.5-3.5-13-1.5-9.5ZM1.5-9.5 3.5-13 6-9.5ZM-6.5-6Q0-1 6.5-6Q4.5-2.5 0-2.2Q-4.5-2.5-6.5-6Z"
      />
    </g>
  )
}

function FullScene() {
  return (
    <svg
      viewBox="0 0 220 180"
      className="halloween-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <SceneDefs />
      <Stars stars={STARS} />
      <Moon x={166} y={44} radius={18} />

      <Bat className="halloween-scene-orbit" x={166} y={44} scale={0.55} />
      <Bat className="halloween-scene-fly" x={240} y={64} scale={0.9} />
      <Bat
        className="halloween-scene-fly halloween-scene-fly-late"
        x={240}
        y={26}
        scale={0.6}
      />

      <path
        d="M0 150C40 128 80 126 120 138c30 8 60-6 100-14v56H0Z"
        fill="currentColor"
        fillOpacity="0.05"
      />

      <path
        fill="currentColor"
        fillOpacity="0.16"
        d="M30 142c3-14 5-30 3-46-2-8-7-14-15-18l2-2c7 3 12 7 15 13 1-9-1-19-5-27l3-1c4 8 6 17 6 26 3-9 9-15 19-19l1 3c-9 4-15 11-17 21-1 12 0 30 4 50ZM58 70l8-6 1 2-7 6ZM30 62l-4-8 2-1 4 7Z"
      />
      <line
        className="halloween-scene-thread"
        x1="56"
        y1="71"
        x2="56"
        y2="96"
        stroke="currentColor"
        strokeOpacity="0.35"
        strokeWidth="0.6"
      />
      <g className="halloween-scene-spider">
        <Spider x={56} y={98.5} />
      </g>

      <g className="halloween-scene-ghost">
        <path
          d="M150 104c6 0 9 5 9 11v10l-3-2.5-3 2.5-3-2.5-3 2.5-3-2.5-3 2.5v-10c0-6 3-11 9-11Z"
          fill="#FAFAFA"
          stroke="currentColor"
          strokeOpacity="0.2"
          strokeWidth="0.6"
        />
        <ellipse cx="147" cy="113" rx="1.3" ry="1.8" fill="#18181B" />
        <ellipse cx="153" cy="113" rx="1.3" ry="1.8" fill="#18181B" />
        <ellipse cx="150" cy="118" rx="1.3" ry="1.7" fill="#18181B" />
      </g>

      <path
        d="M0 160c30-12 70-14 110-6 30 6 60-4 110-8v34H0Z"
        fill="currentColor"
        fillOpacity="0.09"
      />

      <g fill="currentColor" fillOpacity="0.18">
        <path d="M92 158v-18a9 9 0 0 1 18 0v18Z" />
        <path
          d="M123 157v-10a6 6 0 0 1 12 0v10Z"
          transform="rotate(8 129 157)"
        />
      </g>
      <g stroke="currentColor" strokeOpacity="0.3" strokeWidth="1.4">
        <path d="M101 137v12M97 141h8" />
      </g>

      <Pumpkin x={60} y={164} scale={1.3} delay="0s" />
      <Pumpkin x={83} y={163} scale={0.85} delay="-0.9s" />
      <Pumpkin x={176} y={155} scale={1.1} delay="-1.6s" />

      <g className="halloween-scene-fog" fill="currentColor" fillOpacity="0.05">
        <ellipse cx="70" cy="170" rx="90" ry="7" />
        <ellipse cx="170" cy="164" rx="70" ry="5" />
      </g>
    </svg>
  )
}

/** Narrow strip for the collapsed rail: the spider's thread runs up into the nav. */
function CompactScene() {
  return (
    <svg
      viewBox="0 0 60 170"
      overflow="visible"
      className="halloween-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <SceneDefs />
      <Stars stars={STARS_COMPACT} />
      <Moon x={30} y={36} radius={11} />
      <Bat className="halloween-scene-orbit" x={30} y={36} scale={0.5} />

      <g className="halloween-scene-spider">
        <line
          x1="46"
          y1="-600"
          x2="46"
          y2="96"
          stroke="currentColor"
          strokeOpacity="0.35"
          strokeWidth="0.6"
        />
        <Spider x={46} y={98.5} />
      </g>

      <path
        d="M0 150c15-9 45-9 60 0v20H0Z"
        fill="currentColor"
        fillOpacity="0.09"
      />
      <path
        d="M41 153v-9a5 5 0 0 1 10 0v9Z"
        fill="currentColor"
        fillOpacity="0.18"
      />
      <Pumpkin x={22} y={160} scale={1.1} delay="0s" />

      <g className="halloween-scene-fog" fill="currentColor" fillOpacity="0.05">
        <ellipse cx="30" cy="164" rx="40" ry="5" />
      </g>
    </svg>
  )
}

/**
 * Moonlit graveyard that fills the free sidebar space above Settings, or a
 * narrow strip of it on the collapsed rail. Parent must be a flex column; the
 * scene only takes space nothing else needs.
 */
export function HalloweenScene({ compact = false }: { compact?: boolean }) {
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
