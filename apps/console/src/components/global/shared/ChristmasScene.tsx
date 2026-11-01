import { useId } from 'react'
import {
  CHRISTMAS_BULB_COLORS,
  ChristmasBulbGlows,
} from '@/components/global/shared/ChristmasLights'
import { HolidaySidebarScene } from '@/components/global/shared/HolidaySidebarScene'

const SNOW_CLASS = 'fill-white dark:fill-slate-200/45'
const GROUND_CLASS = 'fill-slate-100 dark:fill-slate-200/[0.05]'
const DRIFT_CLASS = 'fill-white dark:fill-slate-200/[0.08]'
const SILHOUETTE = '#1C1917'

const STARS = [
  [20, 30, 0],
  [58, 14, 1.4],
  [104, 36, 0.6],
  [132, 12, 2.1],
  [204, 30, 1],
  [88, 66, 1.8],
] as const

const STARS_RAIL = [
  [10, 20, 0],
  [22, 52, 1.2],
  [52, 64, 0.5],
] as const

type Pine = readonly [x: number, base: number, height: number]

const FAR_PINES: readonly Pine[] = [
  [8, 136, 22],
  [20, 134, 30],
  [34, 136, 18],
  [104, 132, 20],
  [116, 131, 28],
  [128, 132, 16],
  [196, 128, 26],
  [208, 129, 20],
  [218, 128, 30],
]

const NEAR_PINES: readonly Pine[] = [
  [16, 170, 40],
  [126, 166, 30],
  [204, 168, 44],
]

/** Three-tier fir with a snow cap on each tier, as one silhouette path. */
function pineTiers(x: number, base: number, height: number) {
  return [0, 1, 2].map((tier) => {
    const apex = base - height * (1 - 0.27 * tier)
    const bottom = base - height * (0.55 - 0.27 * tier)
    const half = height * 0.24 * (0.6 + 0.2 * tier)
    const capY = apex + (bottom - apex) * 0.42
    const capHalf = half * 0.42
    return {
      body: `M${x} ${apex}L${x + half} ${bottom}Q${x} ${bottom - 1.6} ${x - half} ${bottom}Z`,
      shade: `M${x} ${apex}L${x + half} ${bottom}Q${x + half / 2} ${bottom - 1.2} ${x} ${bottom - 0.8}Z`,
      cap: `M${x} ${apex}L${x + capHalf} ${capY}Q${x + capHalf / 2} ${capY + 2.4} ${x} ${capY + 0.8}Q${x - capHalf / 2} ${capY + 2.4} ${x - capHalf} ${capY}Z`,
    }
  })
}

function Pines({
  pines,
  opacity,
}: {
  pines: readonly Pine[]
  opacity: number
}) {
  return pines.map(([x, base, height]) => (
    <g key={`${x}-${base}`}>
      <rect
        x={x - height * 0.03}
        y={base - 1}
        width={height * 0.06}
        height={height * 0.1}
        fill="currentColor"
        fillOpacity={opacity}
      />
      {pineTiers(x, base, height).map((tier, index) => (
        <g key={index}>
          <path d={tier.body} fill="currentColor" fillOpacity={opacity} />
          <path d={tier.cap} className={SNOW_CLASS} />
        </g>
      ))}
    </g>
  ))
}

function Stars({
  stars,
}: {
  stars: ReadonlyArray<readonly [number, number, number]>
}) {
  return stars.map(([cx, cy, delay]) => (
    <circle
      key={`${cx}-${cy}`}
      className="christmas-twinkle"
      style={{ animationDelay: `${delay}s` }}
      cx={cx}
      cy={cy}
      r="0.9"
      fill="currentColor"
    />
  ))
}

function Moon({ x, y, radius }: { x: number; y: number; radius: number }) {
  const glowId = useId().replace(/:/g, '')
  return (
    <g>
      <radialGradient id={glowId}>
        <stop offset="0%" stopColor="#FEF3C7" stopOpacity="0.5" />
        <stop offset="100%" stopColor="#FEF3C7" stopOpacity="0" />
      </radialGradient>
      <circle cx={x} cy={y} r={radius * 2.6} fill={`url(#${glowId})`} />
      <circle
        cx={x}
        cy={y}
        r={radius}
        fill="#FEFCE8"
        stroke="#FDE68A"
        strokeOpacity="0.6"
        strokeWidth="0.6"
      />
      <circle
        cx={x - radius * 0.3}
        cy={y - radius * 0.25}
        r={radius * 0.18}
        fill="#FEF3C7"
      />
      <circle
        cx={x + radius * 0.35}
        cy={y + radius * 0.3}
        r={radius * 0.12}
        fill="#FEF3C7"
      />
    </g>
  )
}

function SleighShapes() {
  const deer = (dx: number) => (
    <g key={dx} transform={`translate(${dx} 0)`}>
      <ellipse cx="5" cy="0" rx="4.3" ry="1.7" />
      <path d="M2.2-.8.4-4l-1.8-.3-1 .9 1.9.2 1.2 3.2Z" />
      <path
        d="M0-4.1l-.7-2.3m.3 1-1.1-.8m1.8 2 .6-2.3m-.3 1 1-.7"
        fill="none"
        strokeWidth="0.5"
      />
      <path
        d="M2.2.8 0 2.6m3-1.8-1.4 2.3m6.3-2.4 2.3 1.5m-2.9-1.3 1.6 2.1"
        fill="none"
        strokeWidth="0.7"
        strokeLinecap="round"
      />
    </g>
  )

  return (
    <>
      {deer(0)}
      {deer(12)}
      <path d="M8.5-.4 11-.6m9 .2L27-.2" fill="none" strokeWidth="0.35" />
      <path
        d="M25.6 2.6c-1 0-1.2 1.7.1 1.7h16.6"
        fill="none"
        strokeWidth="0.6"
      />
      <path d="M29 2.6v1.6m10-1.6v1.6" fill="none" strokeWidth="0.5" />
      <path d="M27.4 2.8h12.8c1.5 0 2.4-1.3 2.4-3v-3.3h-2v2c0 .8-.6 1.3-1.4 1.3h-8c-1.2 0-1.8-1-2.4-2.3l-1.4.5c.5 2 .1 3.4 0 4.8Z" />
      <path d="M33.3-.6c-.2-2.6.8-4.4 2.6-4.4s2.6 1.8 2.4 4.4Z" />
      <circle cx="35.9" cy="-6" r="1.3" />
      <path d="M34.8-6.9 37.3-7l2.3 1.3-1.9-2.4Z" />
      <circle cx="40.5" cy="-3.6" r="2.3" />
    </>
  )
}

/**
 * Santa and two reindeer in silhouette, facing left, about 46 wide. A faint
 * rim keeps them visible against the dark sky away from the moon.
 */
function Sleigh() {
  return (
    <g strokeLinejoin="round">
      <g
        fill="currentColor"
        fillOpacity="0.25"
        stroke="currentColor"
        strokeOpacity="0.25"
        strokeWidth="1.1"
      >
        <SleighShapes />
      </g>
      <g fill={SILHOUETTE} stroke={SILHOUETTE}>
        <SleighShapes />
      </g>
    </g>
  )
}

function Tree({
  x,
  base,
  height,
}: {
  x: number
  base: number
  height: number
}) {
  const glowId = useId().replace(/:/g, '')
  const tiers = pineTiers(x, base, height)
  const lights = [
    [-3, 0.3],
    [4, 0.36],
    [-6, 0.5],
    [2, 0.56],
    [7, 0.66],
    [-9, 0.74],
    [-2, 0.8],
    [9, 0.88],
    [-12, 0.95],
    [4, 0.98],
  ] as const

  return (
    <g>
      <ChristmasBulbGlows id={glowId} />
      <rect
        x={x - 2}
        y={base - 1}
        width="4"
        height="7"
        rx="0.8"
        fill="#78350F"
      />
      {tiers.map((tier, index) => (
        <g key={index}>
          <path d={tier.body} fill="#15803D" />
          <path d={tier.shade} fill="#14532D" fillOpacity="0.55" />
          <path d={tier.cap} className={SNOW_CLASS} />
        </g>
      ))}
      {lights.map(([dx, depth], index) => {
        const y = base - height + height * depth
        return (
          <g key={index}>
            <circle
              className="christmas-halo opacity-60 dark:opacity-100"
              style={{ animationDelay: `${index * -0.37}s` }}
              cx={x + dx * (height / 60)}
              cy={y}
              r="4.5"
              fill={`url(#${glowId}-${index % CHRISTMAS_BULB_COLORS.length})`}
            />
            <circle
              className="christmas-bulb"
              style={{ animationDelay: `${index * -0.37}s` }}
              cx={x + dx * (height / 60)}
              cy={y}
              r="1.3"
              fill={CHRISTMAS_BULB_COLORS[index % CHRISTMAS_BULB_COLORS.length]}
            />
          </g>
        )
      })}
      <g transform={`translate(${x} ${base - height - 1})`}>
        <circle
          r="9"
          fill="#FDE68A"
          fillOpacity="0.18"
          className="christmas-glow"
        />
        <g
          className="christmas-rays"
          stroke="#FDE68A"
          strokeWidth="0.5"
          strokeLinecap="round"
        >
          <path d="M0-9v4m0 10v4M-9 0h4m10 0h4" strokeOpacity="0.7" />
          <path
            d="m-5.5-5.5 2 2m7 7 2 2m0-11-2 2m-7 7-2 2"
            strokeOpacity="0.4"
          />
        </g>
        <path
          d="M0-4.6 1.2-1.5 4.4-1.2 2-1 2.8 3.7 0 2-2.8 3.7-2-1-4.4-1.2-1.2-1.5Z"
          fill="#FBBF24"
          stroke="#F59E0B"
          strokeWidth="0.3"
        />
      </g>
    </g>
  )
}

function Gift({
  x,
  base,
  width,
  height,
  fill,
}: {
  x: number
  base: number
  width: number
  height: number
  fill: string
}) {
  return (
    <g transform={`translate(${x} ${base})`}>
      <rect
        x={-width / 2}
        y={-height}
        width={width}
        height={height}
        rx="0.8"
        fill={fill}
      />
      <rect
        x={-width / 2}
        y={-height}
        width={width / 2}
        height={height}
        fill="#000"
        fillOpacity="0.12"
      />
      <rect x="-0.8" y={-height} width="1.6" height={height} fill="#FDE68A" />
      <path
        d={`M0 ${-height}c-1.6-2.4-3.8-2-3.2-.6.4.8 2 .9 3.2.6Zm0 0c1.6-2.4 3.8-2 3.2-.6-.4.8-2 .9-3.2.6Z`}
        fill="#FDE68A"
      />
    </g>
  )
}

function Cabin() {
  const glowId = useId().replace(/:/g, '')
  const smokeId = `${glowId}-smoke`
  return (
    <g>
      <radialGradient id={glowId}>
        <stop offset="0%" stopColor="#FBBF24" stopOpacity="0.55" />
        <stop offset="100%" stopColor="#FBBF24" stopOpacity="0" />
      </radialGradient>
      <radialGradient id={smokeId}>
        <stop offset="0%" stopColor="currentColor" stopOpacity="0.22" />
        <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
      </radialGradient>
      {[0, -1.4, -2.8].map((delay) => (
        <circle
          key={delay}
          className="christmas-smoke"
          style={{ animationDelay: `${delay}s` }}
          cx="176"
          cy="120"
          r="3.4"
          fill={`url(#${smokeId})`}
        />
      ))}
      <rect
        x="174"
        y="122"
        width="4.4"
        height="9"
        fill="currentColor"
        fillOpacity="0.3"
      />
      <rect
        x="173.4"
        y="120.8"
        width="5.6"
        height="1.8"
        rx="0.9"
        className={SNOW_CLASS}
      />
      <rect
        x="150"
        y="136"
        width="30"
        height="18"
        fill="currentColor"
        fillOpacity="0.18"
      />
      <path
        d="M146 137.5 165 123l19 14.5Z"
        fill="currentColor"
        fillOpacity="0.3"
      />
      <path
        d="M144.6 137.8 165 121.6l20.4 16.2c-.8 1.6-2.3 1.4-3.6.6L165 125.4l-16.8 13c-1.3.8-2.8 1-3.6-.6Z"
        className={SNOW_CLASS}
      />
      <circle
        className="christmas-window opacity-70 dark:opacity-100"
        cx="159"
        cy="144.5"
        r="14"
        fill={`url(#${glowId})`}
      />
      <rect
        className="christmas-window"
        x="155"
        y="141"
        width="8"
        height="7"
        fill="#FBBF24"
      />
      <path
        d="M159 141v7m-4-3.5h8"
        stroke="#92400E"
        strokeOpacity="0.7"
        strokeWidth="0.6"
      />
      <path
        d="M168 154v-9a3 3 0 0 1 6 0v9Z"
        fill="currentColor"
        fillOpacity="0.3"
      />
      <circle
        cx="171"
        cy="144.5"
        r="2"
        fill="none"
        stroke="#16A34A"
        strokeWidth="1"
      />
      <circle cx="171" cy="146.4" r="0.6" fill="#DC2626" />
    </g>
  )
}

function Snowfall({ width, count }: { width: number; count: number }) {
  return Array.from({ length: count }, (_, index) => {
    const duration = 7 + ((index * 13) % 7)
    return (
      <g
        key={index}
        className="christmas-sway"
        style={{ animationDelay: `${(index * -0.9) % 4}s` }}
      >
        <circle
          className="christmas-snowfall fill-slate-300 dark:fill-white"
          style={{
            animationDuration: `${duration}s`,
            animationDelay: `${-((index * 2.3) % duration)}s`,
          }}
          cx={((index * 47) % width) + 3}
          cy="-8"
          r={0.7 + ((index * 7) % 5) * 0.22}
          fillOpacity={0.55 + ((index * 3) % 4) * 0.1}
        />
      </g>
    )
  })
}

function FullScene() {
  return (
    <svg
      viewBox="0 0 220 200"
      className="holiday-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Stars stars={STARS} />
      <Moon x={170} y={50} radius={15} />
      <g transform="translate(0 50)">
        <g className="christmas-sleigh">
          <g className="christmas-sleigh-bob">
            <g transform="scale(1.15)">
              <Sleigh />
            </g>
          </g>
        </g>
      </g>

      <path
        d="M0 134c40-14 90-16 130-8 35 7 65-2 90-6v80H0Z"
        fill="currentColor"
        fillOpacity="0.04"
      />
      <Pines pines={FAR_PINES} opacity={0.09} />
      <path
        d="M0 152c34-12 80-16 120-10 36 5 66 1 100-4v62H0Z"
        className={GROUND_CLASS}
      />
      <Cabin />
      <Pines pines={NEAR_PINES} opacity={0.16} />
      <Tree x={66} base={166} height={64} />
      <Gift x={84} base={172} width={10} height={8} fill="#DC2626" />
      <Gift x={95} base={173} width={8} height={6} fill="#2563EB" />
      <Gift x={48} base={173} width={9} height={7} fill="#B45309" />

      <Snowfall width={214} count={30} />

      <path
        d="M0 172c30-6 64-6 98-1 38 5 80 2 122-6v35H0Z"
        className={DRIFT_CLASS}
      />
      <path
        d="M0 172c30-6 64-6 98-1 38 5 80 2 122-6"
        fill="none"
        stroke="currentColor"
        strokeOpacity="0.08"
      />
    </svg>
  )
}

/** Narrow strip for the collapsed rail: one tree under the moon. */
function RailScene() {
  return (
    <svg
      viewBox="0 0 60 170"
      className="holiday-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Stars stars={STARS_RAIL} />
      <Moon x={40} y={34} radius={8} />
      <path d="M0 142c18-8 42-8 60-2v30H0Z" className={GROUND_CLASS} />
      <Tree x={30} base={150} height={46} />
      <Snowfall width={56} count={9} />
      <path d="M0 154c20-4 40-4 60 0v16H0Z" className={DRIFT_CLASS} />
      <path
        d="M0 154c20-4 40-4 60 0"
        fill="none"
        stroke="currentColor"
        strokeOpacity="0.08"
      />
    </svg>
  )
}

/**
 * Snowy forest with a lit cabin, a decorated tree, and Santa crossing the
 * moon, filling the free sidebar space above Settings.
 */
export function ChristmasScene({ compact = false }: { compact?: boolean }) {
  return (
    <HolidaySidebarScene
      compact={compact}
      scene={<FullScene />}
      rail={<RailScene />}
    />
  )
}
