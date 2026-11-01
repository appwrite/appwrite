import { useId } from 'react'
import { HANUKKAH_NIGHTS, isHanukkahBranchLit } from '@/lib/hanukkah'

/** Height of the branch cups above the base; the shamash sits higher. */
const CUP_Y = -36
const SHAMASH_Y = -45
const BRANCH_GAP = 7.2

/** The colorful twisted candles sold in boxes all over Israel. */
const CANDLE_COLORS = [
  '#3B82F6',
  '#F8FAFC',
  '#FACC15',
  '#EF4444',
  '#22C55E',
  '#F97316',
  '#A855F7',
  '#EC4899',
] as const

/** Branch x positions from left to right; `isHanukkahBranchLit` counts the same way. */
const BRANCH_XS = Array.from({ length: HANUKKAH_NIGHTS }, (_, index) => {
  const pair = index < 4 ? 4 - index : index - 3
  return (index < 4 ? -1 : 1) * pair * BRANCH_GAP
})

/** Flame standing on a wick at the origin, with its own glow. */
export function HanukkahFlame({
  glowId,
  delay,
  large = false,
}: {
  glowId: string
  delay: string
  large?: boolean
}) {
  return (
    <g>
      <circle
        className="hanukkah-halo"
        style={{ animationDelay: delay }}
        cy="-3.6"
        r={large ? 9 : 7.5}
        fill={`url(#${glowId})`}
      />
      <path d="M0 0v-1.2" stroke="#292524" strokeWidth="0.45" />
      <g
        className="hanukkah-flame"
        style={{ animationDelay: delay }}
        transform={large ? 'scale(1.15)' : undefined}
      >
        <path
          d="M0-8C2-4.8 2.5-2.4 1.8-.8 1.3.3-1.3.3-1.8-.8-2.5-2.4-2-4.8 0-8Z"
          fill="#F59E0B"
        />
        <path
          d="M0-6.2C1.2-3.8 1.5-2.2 1.1-1.1.8-.4-.8-.4-1.1-1.1-1.5-2.2-1.2-3.8 0-6.2Z"
          fill="#FDE68A"
        />
        <ellipse cy="-1" rx="0.8" ry="0.9" fill="#93C5FD" fillOpacity="0.85" />
      </g>
    </g>
  )
}

/** Shared glow fill for flames; render once per SVG and pass the id to flames. */
export function HanukkahFlameGlow({ id }: { id: string }) {
  return (
    <radialGradient id={id}>
      <stop offset="0%" stopColor="#FCD34D" stopOpacity="0.55" />
      <stop offset="100%" stopColor="#FCD34D" stopOpacity="0" />
    </radialGradient>
  )
}

function Candle({
  x,
  top,
  height,
  color,
  glowId,
  delay,
  large = false,
}: {
  x: number
  top: number
  height: number
  color: string
  glowId: string
  delay: string
  large?: boolean
}) {
  const width = large ? 2.6 : 2.2
  return (
    <g transform={`translate(${x} ${top})`}>
      <rect
        x={-width / 2}
        y={-height}
        width={width}
        height={height}
        rx="0.5"
        fill={color}
      />
      <path
        d={`M${-width / 2} ${-height * 0.25}l${width} -1.6M${-width / 2} ${-height * 0.55}l${width} -1.6M${-width / 2} ${-height * 0.85}l${width} -1.6`}
        stroke="#FFFFFF"
        strokeOpacity="0.45"
        strokeWidth="0.5"
      />
      <rect
        x={width / 2 - 0.7}
        y={-height}
        width="0.7"
        height={height}
        fill="#000"
        fillOpacity="0.18"
      />
      <g transform={`translate(0 ${-height})`}>
        <HanukkahFlame glowId={glowId} delay={delay} large={large} />
      </g>
    </g>
  )
}

function Cup({ x, y }: { x: number; y: number }) {
  return <path d={`M${x - 2.4} ${y}h4.8l-.7 1.8h-3.4Z`} fill="#CA8A04" />
}

/**
 * Arched-branch hanukkiah standing on its base at (`x`, `y`), about 64 wide.
 * Tonight's candles are lit right to left; the shamash burns on every night.
 */
export function Hanukkiah({
  night,
  x = 0,
  y = 0,
  scale = 1,
}: {
  night: number
  x?: number
  y?: number
  scale?: number
}) {
  const id = useId().replace(/:/g, '')
  const goldId = `${id}-gold`
  const glowId = `${id}-glow`

  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <defs>
        <linearGradient
          id={goldId}
          gradientUnits="userSpaceOnUse"
          x1="-32"
          x2="32"
        >
          <stop offset="0%" stopColor="#A16207" />
          <stop offset="35%" stopColor="#FACC15" />
          <stop offset="55%" stopColor="#FEF08A" />
          <stop offset="100%" stopColor="#A16207" />
        </linearGradient>
        <HanukkahFlameGlow id={glowId} />
      </defs>

      <g fill="none" stroke={`url(#${goldId})`} strokeLinecap="round">
        {[1, 2, 3, 4].map((pair) => {
          const radius = pair * BRANCH_GAP
          return (
            <path
              key={pair}
              d={`M${-radius} ${CUP_Y}A${radius} ${radius} 0 0 0 ${radius} ${CUP_Y}`}
              strokeWidth="2"
            />
          )
        })}
        <path d={`M0 -3V${SHAMASH_Y}`} strokeWidth="2.6" />
      </g>
      <ellipse cy="-19" rx="2.4" ry="1.3" fill={`url(#${goldId})`} />
      <path d="M-13 0h26l-3.4-3.4H-9.6Z" fill={`url(#${goldId})`} />
      <rect x="-13" y="0" width="26" height="1.6" rx="0.6" fill="#A16207" />

      {BRANCH_XS.map((branchX) => (
        <Cup key={branchX} x={branchX} y={CUP_Y} />
      ))}
      <Cup x={0} y={SHAMASH_Y} />

      {BRANCH_XS.map((branchX, index) =>
        isHanukkahBranchLit(index, night) ? (
          <Candle
            key={branchX}
            x={branchX}
            top={CUP_Y}
            height={8}
            color={CANDLE_COLORS[index]}
            glowId={glowId}
            delay={`${(index % 5) * -0.37}s`}
          />
        ) : null,
      )}
      {night > 0 ? (
        <Candle
          x={0}
          top={SHAMASH_Y}
          height={9.5}
          color="#F8FAFC"
          glowId={glowId}
          delay="-0.2s"
          large
        />
      ) : null}
    </g>
  )
}
