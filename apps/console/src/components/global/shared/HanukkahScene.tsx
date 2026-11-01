import { useId } from 'react'
import { Dreidel } from '@/components/global/shared/HanukkahDreidel'
import { Hanukkiah } from '@/components/global/shared/HanukkahMenorah'
import { HolidaySidebarScene } from '@/components/global/shared/HolidaySidebarScene'

/** Jerusalem stone: warm limestone in light mode, a soft glow of it in dark. */
const STONE_CLASS = 'fill-[#E7DCC3] dark:fill-[#CBB892]/30'
const STONE_SHADE_CLASS = 'fill-[#D3C4A1] dark:fill-[#CBB892]/20'
const CITY = '#16213F'
/** Crenellated Old City wall across the window. */
const WALL = `M50 150V136${'h4v2h4v-2'.repeat(15)}V150Z`

const STARS = [
  [72, 52, 0],
  [96, 44, 1.3],
  [128, 50, 0.5],
  [150, 62, 1.9],
  [64, 74, 1.1],
  [112, 66, 2.3],
  [158, 82, 0.8],
] as const

/** City windows lit for the holiday; [x, y, flicker delay]. */
const WINDOWS = [
  [58, 128, 0],
  [62, 134, 1.4],
  [96, 126, 0.6],
  [101, 132, 2.1],
  [123, 129, 1],
  [140, 124, 1.7],
  [146, 131, 0.3],
  [160, 127, 2.4],
] as const

function Sky({ id }: { id: string }) {
  return (
    <defs>
      <linearGradient id={`${id}-sky`} x1="0" x2="0" y1="0" y2="1">
        <stop offset="0%" stopColor="#0B1437" />
        <stop offset="60%" stopColor="#1E3A8A" />
        <stop offset="100%" stopColor="#4F6BD8" />
      </linearGradient>
      <radialGradient id={`${id}-horizon`} cy="1" r="0.8">
        <stop offset="0%" stopColor="#FDBA74" stopOpacity="0.45" />
        <stop offset="100%" stopColor="#FDBA74" stopOpacity="0" />
      </radialGradient>
      <radialGradient id={`${id}-pool`}>
        <stop offset="0%" stopColor="#FCD34D" stopOpacity="0.5" />
        <stop offset="100%" stopColor="#FCD34D" stopOpacity="0" />
      </radialGradient>
      <linearGradient id={`${id}-glass`} x1="0" x2="1" y1="0" y2="1">
        <stop offset="0%" stopColor="#FFF" stopOpacity="0" />
        <stop offset="45%" stopColor="#FFF" stopOpacity="0.07" />
        <stop offset="55%" stopColor="#FFF" stopOpacity="0" />
      </linearGradient>
    </defs>
  )
}

/** Old City skyline: the walls, David's Citadel, domes, and stone houses. */
function Skyline() {
  return (
    <g>
      <path
        fill={CITY}
        fillOpacity="0.75"
        d="M50 150v-26h10v-6h8v8h12v-4h10v10h14v-8h6v8h10v-6h16v6h10v-10h10v6h14v22Z"
      />
      <g fill={CITY}>
        <path d="M76 150v-36h2v-8h1.2v-6l1.8-4 1.8 4v6h1.2v8h2v36Z" />
        <path d="M74.6 114h11.8v2H74.6Z" />
        <path d="M118 150v-16h20v16Z" />
        <path d="M120 134a8 8 0 0 1 16 0Z" />
        <path d="M127.4 126v-3h1.2v3Z" />
        <path d="M150 150v-12h12v12Z" />
        <path d="M151.6 138a4.4 4.4 0 0 1 8.8 0Z" />
        <path d={WALL} />
      </g>
      {WINDOWS.map(([x, y, delay]) => (
        <rect
          key={`${x}-${y}`}
          className="hanukkah-window"
          style={{ animationDelay: `${delay}s` }}
          x={x}
          y={y}
          width="1.6"
          height="2.2"
          rx="0.5"
          fill="#FCD34D"
        />
      ))}
    </g>
  )
}

/** Jelly doughnut with a fried ring, powdered sugar, and jam on top. */
function Sufganiya({ x, y }: { x: number; y: number }) {
  return (
    <g transform={`translate(${x} ${y})`}>
      <ellipse cy="-0.4" rx="10" ry="2" fill="#FFFFFF" />
      <ellipse cy="-0.8" rx="10" ry="2" fill="#000" fillOpacity="0.08" />
      <ellipse cy="-5.4" rx="7.4" ry="5" fill="#B45309" />
      <ellipse cy="-5" rx="7.4" ry="1.5" fill="#FDE68A" fillOpacity="0.85" />
      <path
        d="M-6.4-7.2c1.4-2.6 11.4-2.6 12.8 0-2 1.1-10.8 1.1-12.8 0Z"
        fill="#FFFBEB"
      />
      <g fill="#FFFFFF">
        <circle cx="-3.6" cy="-8.4" r="0.6" />
        <circle cx="3.8" cy="-8.2" r="0.5" />
        <circle cx="1.2" cy="-9.6" r="0.4" />
      </g>
      <ellipse cy="-9.2" rx="1.8" ry="1.1" fill="#DC2626" />
      <ellipse cx="-0.5" cy="-9.5" rx="0.5" ry="0.3" fill="#FCA5A5" />
    </g>
  )
}

function FullScene({ night }: { night: number }) {
  const id = useId().replace(/:/g, '')
  const opening = 'M50 150V102a60 60 0 0 1 120 0v48Z'

  return (
    <svg
      viewBox="0 12 220 156"
      className="holiday-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Sky id={id} />
      <path d="M40 154V102a70 70 0 0 1 140 0v52Z" className={STONE_CLASS} />
      <path
        d="M40 154V102a70 70 0 0 1 140 0v52h-3V102a67 67 0 0 0-134 0v52Z"
        className={STONE_SHADE_CLASS}
      />
      <clipPath id={`${id}-opening`}>
        <path d={opening} />
      </clipPath>
      <g clipPath={`url(#${id}-opening)`}>
        <path d={opening} fill={`url(#${id}-sky)`} />
        <rect
          x="50"
          y="100"
          width="120"
          height="50"
          fill={`url(#${id}-horizon)`}
        />
        {STARS.map(([cx, cy, delay]) => (
          <circle
            key={`${cx}-${cy}`}
            className="hanukkah-twinkle"
            style={{ animationDelay: `${delay}s` }}
            cx={cx}
            cy={cy}
            r="0.8"
            fill="#E0E7FF"
          />
        ))}
        <Skyline />
        <path d={opening} fill={`url(#${id}-glass)`} />
      </g>
      <path d={opening} fill="none" stroke="#000" strokeOpacity="0.15" />

      {night > 0 ? (
        <ellipse
          className="hanukkah-halo"
          cx="110"
          cy="150"
          rx="62"
          ry="10"
          fill={`url(#${id}-pool)`}
        />
      ) : null}
      <rect
        x="32"
        y="150"
        width="156"
        height="6"
        rx="1.5"
        className={STONE_CLASS}
      />
      <rect
        x="34"
        y="156"
        width="152"
        height="4"
        className={STONE_SHADE_CLASS}
      />

      <Hanukkiah night={night} x={110} y={150} scale={1.05} />
      <Sufganiya x={58} y={150} />
      <ellipse
        cx="168"
        cy="150.2"
        rx="4.5"
        ry="0.9"
        fill="#000"
        fillOpacity="0.18"
      />
      <Dreidel x={168} y={150} scale={0.6} topple delay="-1s" />
    </svg>
  )
}

/** Narrow strip for the collapsed rail: the hanukkiah in a slim window. */
function RailScene({ night }: { night: number }) {
  const id = useId().replace(/:/g, '')
  const opening = 'M10 144V86a20 20 0 0 1 40 0v58Z'

  return (
    <svg
      viewBox="0 0 60 170"
      className="holiday-scene-in absolute inset-x-0 bottom-0 block h-auto w-full text-foreground"
    >
      <Sky id={id} />
      <path d="M5 148V86a25 25 0 0 1 50 0v62Z" className={STONE_CLASS} />
      <clipPath id={`${id}-opening`}>
        <path d={opening} />
      </clipPath>
      <g clipPath={`url(#${id}-opening)`}>
        <path d={opening} fill={`url(#${id}-sky)`} />
        <rect
          x="10"
          y="110"
          width="40"
          height="34"
          fill={`url(#${id}-horizon)`}
        />
        <circle
          className="hanukkah-twinkle"
          cx="22"
          cy="80"
          r="0.7"
          fill="#E0E7FF"
        />
        <circle
          className="hanukkah-twinkle"
          style={{ animationDelay: '1.2s' }}
          cx="40"
          cy="92"
          r="0.7"
          fill="#E0E7FF"
        />
        <path
          fill={CITY}
          d="M10 144v-12h6v-4h4v6h6v-14h2v-4l1-2 1 2v4h2v14h6v-6h5v-4h7v20Z"
        />
      </g>
      <rect
        x="2"
        y="144"
        width="56"
        height="5"
        rx="1.2"
        className={STONE_CLASS}
      />
      <Hanukkiah night={night} x={30} y={144} scale={0.62} />
    </svg>
  )
}

/**
 * A hanukkiah lit for tonight in an arched Jerusalem-stone window, with the
 * Old City at blue hour outside, filling the free sidebar space above Settings.
 */
export function HanukkahScene({
  compact = false,
  night,
}: {
  compact?: boolean
  night: number
}) {
  return (
    <HolidaySidebarScene
      compact={compact}
      scene={<FullScene night={night} />}
      rail={<RailScene night={night} />}
    />
  )
}
