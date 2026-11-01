import { useId, type CSSProperties } from 'react'
import { HolidaySidebarScene } from '@/components/global/shared/HolidaySidebarScene'

const GROUND_Y = 162
/** Reflections are squashed so the whole show fits in the pool. */
const REFLECTION = `translate(0 ${GROUND_Y}) scale(1 -0.3) translate(0 ${-GROUND_Y})`
const MONUMENT_CLASS = 'fill-slate-300 dark:fill-slate-200/60'
const MONUMENT_SHADE_CLASS = 'fill-slate-400 dark:fill-slate-300/35'
const POOL_CLASS = 'fill-sky-100 dark:fill-sky-200/[0.06]'

const COLORS = {
  red: '#EF4444',
  blue: '#3B82F6',
  gold: '#F59E0B',
  silver: '#94A3B8',
} as const

type ShellKind = 'peony' | 'ring' | 'willow'

type Shell = {
  x: number
  y: number
  radius: number
  color: keyof typeof COLORS
  inner?: keyof typeof COLORS
  kind: ShellKind
  delay: number
}

const SHELLS: readonly Shell[] = [
  {
    x: 62,
    y: 62,
    radius: 24,
    color: 'red',
    inner: 'silver',
    kind: 'peony',
    delay: 0,
  },
  { x: 160, y: 50, radius: 20, color: 'blue', kind: 'ring', delay: -1.6 },
  { x: 110, y: 34, radius: 28, color: 'gold', kind: 'willow', delay: -3.1 },
  {
    x: 184,
    y: 96,
    radius: 13,
    color: 'silver',
    inner: 'red',
    kind: 'peony',
    delay: -2.4,
  },
  {
    x: 34,
    y: 100,
    radius: 12,
    color: 'blue',
    inner: 'gold',
    kind: 'peony',
    delay: -0.8,
  },
]

const SHELLS_RAIL: readonly Shell[] = [
  {
    x: 30,
    y: 46,
    radius: 18,
    color: 'red',
    inner: 'silver',
    kind: 'peony',
    delay: 0,
  },
  { x: 18, y: 84, radius: 10, color: 'blue', kind: 'ring', delay: -2.4 },
]

/** Point `index` of `count` around a burst, with a little spread so it isn't a perfect ring. */
function polar(radius: number, index: number, count: number) {
  const angle = (index / count) * Math.PI * 2 + Math.sin(index * 2.7) * 0.06
  const spread = radius * (0.9 + 0.1 * Math.sin(index * 1.9))
  return [spread * Math.cos(angle), spread * Math.sin(angle)] as const
}

function Particles({ shell }: { shell: Shell }) {
  const color = COLORS[shell.color]

  if (shell.kind === 'willow') {
    return (
      <g
        className="july-glitter"
        stroke={color}
        strokeWidth="0.8"
        strokeLinecap="round"
      >
        {Array.from({ length: 26 }, (_, index) => {
          const [x1, y1] = polar(shell.radius * 0.35, index, 26)
          const [x2, y2] = polar(shell.radius, index, 26)
          return (
            <path
              key={index}
              d={`M${x1.toFixed(1)} ${y1.toFixed(1)}L${x2.toFixed(1)} ${y2.toFixed(1)}`}
              strokeOpacity={index % 2 === 0 ? 1 : 0.6}
            />
          )
        })}
      </g>
    )
  }

  const count = shell.kind === 'ring' ? 18 : 22
  return (
    <g>
      {Array.from({ length: count }, (_, index) => {
        const [x, y] = polar(shell.radius, index, count)
        return (
          <g key={index}>
            <path
              d={`M${(x * 0.72).toFixed(1)} ${(y * 0.72).toFixed(1)}L${x.toFixed(1)} ${y.toFixed(1)}`}
              stroke={color}
              strokeOpacity="0.45"
              strokeWidth="0.7"
              strokeLinecap="round"
            />
            <circle cx={x} cy={y} r="1.2" fill={color} />
          </g>
        )
      })}
      {shell.inner
        ? Array.from({ length: 12 }, (_, index) => {
            const [x, y] = polar(shell.radius * 0.5, index + 0.5, 12)
            return (
              <circle
                key={index}
                cx={x}
                cy={y}
                r="0.9"
                fill={COLORS[shell.inner!]}
              />
            )
          })
        : null}
    </g>
  )
}

/** One shell: the rocket climbs from the ground, bursts, then drifts and fades. */
function Firework({ shell, glowId }: { shell: Shell; glowId: string }) {
  const delay = `${shell.delay}s`
  const rise = {
    '--rise': `${GROUND_Y - shell.y}px`,
    animationDelay: delay,
  } as CSSProperties

  return (
    <g transform={`translate(${shell.x} ${shell.y})`}>
      <g className="july-rocket" style={rise}>
        <path
          d="M0 0v7"
          stroke={COLORS[shell.color]}
          strokeOpacity="0.6"
          strokeWidth="0.8"
          strokeLinecap="round"
        />
        <circle r="0.9" fill="#FEF3C7" />
      </g>
      <circle
        className="july-flash"
        style={{ animationDelay: delay }}
        r={shell.radius * 1.5}
        fill={`url(#${glowId}-${shell.color})`}
      />
      <g className="july-shell" style={{ animationDelay: delay }}>
        <Particles shell={shell} />
      </g>
    </g>
  )
}

function Glows({ id }: { id: string }) {
  return (
    <defs>
      {Object.entries(COLORS).map(([name, color]) => (
        <radialGradient key={name} id={`${id}-${name}`}>
          <stop offset="0%" stopColor={color} stopOpacity="0.35" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </radialGradient>
      ))}
    </defs>
  )
}

function Monument({ x, scale = 1 }: { x: number; scale?: number }) {
  return (
    <g transform={`translate(${x} ${GROUND_Y}) scale(${scale})`}>
      <path
        d="M-3.6 0-2.6-92 0-98.6 2.6-92 3.6 0Z"
        className={MONUMENT_CLASS}
      />
      <path d="M0-98.6 2.6-92 3.6 0H0Z" className={MONUMENT_SHADE_CLASS} />
      <circle
        className="july-beacon"
        cx="-1.6"
        cy="-89"
        r="0.7"
        fill="#EF4444"
      />
      <circle
        className="july-beacon"
        style={{ animationDelay: '-1.1s' }}
        cx="1.6"
        cy="-89"
        r="0.7"
        fill="#EF4444"
      />
    </g>
  )
}

function Capitol() {
  return (
    <g fill="currentColor" fillOpacity="0.16">
      <path d="M176 160v-6h10v-8h12v8h10v6Z" />
      <path d="M185.4 146a6.6 6.6 0 0 1 13.2 0Z" />
      <path d="M191.2 139.4v-3.4h1.6v3.4Z" />
      <path d="M191.6 136v-1.6l.4-.8.4.8v1.6Z" />
    </g>
  )
}

/** Elm canopy along both sides of the Mall, as one soft silhouette. */
function Trees() {
  return (
    <g fill="currentColor" opacity="0.1">
      {[
        [4, 5],
        [12, 7],
        [21, 6],
        [30, 8],
        [40, 6.5],
        [49, 7.5],
        [58, 6],
        [67, 7],
        [75, 5],
        [146, 4.5],
        [153, 6.5],
        [162, 5.5],
        [170, 7],
        [178, 4],
      ].map(([x, r]) => (
        <ellipse key={x} cx={x} cy={GROUND_Y - r * 0.8} rx={r * 1.1} ry={r} />
      ))}
    </g>
  )
}

function Ripples({ width }: { width: number }) {
  return (
    <g
      className="july-ripple"
      stroke="currentColor"
      strokeOpacity="0.14"
      strokeWidth="0.6"
      strokeLinecap="round"
    >
      {Array.from({ length: 9 }, (_, index) => {
        const y = 170 + index * 3.4
        const x = ((index * 37) % (width - 40)) + 10
        return <path key={index} d={`M${x} ${y}h${10 + (index % 3) * 6}`} />
      })}
    </g>
  )
}

function FullScene() {
  const id = useId().replace(/:/g, '')
  const pool = 'M74 166H146L214 200H6Z'
  const show = (
    <>
      {SHELLS.map((shell) => (
        <Firework key={`${shell.x}-${shell.y}`} shell={shell} glowId={id} />
      ))}
      <Monument x={110} />
    </>
  )

  return (
    <svg
      viewBox="0 0 220 200"
      className="holiday-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Glows id={id} />
      <Capitol />
      <Trees />
      {show}
      <rect
        x="0"
        y={GROUND_Y}
        width="220"
        height="4"
        fill="currentColor"
        fillOpacity="0.08"
      />
      <path d={pool} className={POOL_CLASS} />
      <clipPath id={`${id}-pool`}>
        <path d={pool} />
      </clipPath>
      <g clipPath={`url(#${id}-pool)`}>
        <g transform={REFLECTION} opacity="0.4">
          {show}
        </g>
        <Ripples width={220} />
      </g>
      <path
        d={pool}
        fill="none"
        stroke="currentColor"
        strokeOpacity="0.14"
        strokeWidth="0.8"
      />
    </svg>
  )
}

/** Narrow strip for the collapsed rail: the Monument under a burst. */
function RailScene() {
  const id = useId().replace(/:/g, '')
  const pool = 'M18 166H42L58 186H2Z'
  const show = (
    <>
      {SHELLS_RAIL.map((shell) => (
        <Firework key={`${shell.x}-${shell.y}`} shell={shell} glowId={id} />
      ))}
      <Monument x={30} scale={0.8} />
    </>
  )

  return (
    <svg
      viewBox="0 0 60 186"
      className="holiday-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Glows id={id} />
      {show}
      <rect
        x="0"
        y={GROUND_Y}
        width="60"
        height="4"
        fill="currentColor"
        fillOpacity="0.08"
      />
      <path d={pool} className={POOL_CLASS} />
      <clipPath id={`${id}-pool`}>
        <path d={pool} />
      </clipPath>
      <g clipPath={`url(#${id}-pool)`}>
        <g transform={REFLECTION} opacity="0.4">
          {show}
        </g>
      </g>
    </svg>
  )
}

/**
 * Fireworks over the Washington Monument, mirrored in the Reflecting Pool,
 * filling the free sidebar space above Settings.
 */
export function FourthOfJulyScene({ compact = false }: { compact?: boolean }) {
  return (
    <HolidaySidebarScene
      compact={compact}
      scene={<FullScene />}
      rail={<RailScene />}
    />
  )
}
