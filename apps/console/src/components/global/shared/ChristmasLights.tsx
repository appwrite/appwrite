import { useId } from 'react'

export const CHRISTMAS_BULB_COLORS = [
  '#EF4444',
  '#22C55E',
  '#FBBF24',
  '#3B82F6',
  '#EC4899',
] as const

const SPAN = 120
/** Quadratic control point; the wire dips to half of it between hooks. */
const DIP = 30
const SPANS = 28
const BULBS_PER_SPAN = 5
/** Sockets twist on a real string, so bulbs never hang quite straight. */
const TILTS = [-16, 9, -5, 14, -9, 4, -13, 11] as const

function wire(t: number): [number, number] {
  return [t * SPAN, (1 - t) ** 2 + 2 * (1 - t) * t * DIP + t ** 2]
}

const BULBS = Array.from({ length: BULBS_PER_SPAN }, (_, index) =>
  wire((index + 0.5) / BULBS_PER_SPAN),
)

/** Glow fills, one per bulb color; reference them as `${id}-${colorIndex}`. */
export function ChristmasBulbGlows({ id }: { id: string }) {
  return (
    <svg width="0" height="0" className="absolute" aria-hidden>
      <defs>
        {CHRISTMAS_BULB_COLORS.map((color, index) => (
          <radialGradient key={color} id={`${id}-${index}`}>
            <stop offset="0%" stopColor={color} stopOpacity="0.75" />
            <stop offset="100%" stopColor={color} stopOpacity="0" />
          </radialGradient>
        ))}
      </defs>
    </svg>
  )
}

/** C9 bulb hanging from its socket at the origin; the glow pulses with the chase. */
export function ChristmasBulb({
  glowId,
  colorIndex,
  delay,
  scale = 1,
}: {
  glowId: string
  colorIndex: number
  delay: string
  scale?: number
}) {
  const color = CHRISTMAS_BULB_COLORS[colorIndex % CHRISTMAS_BULB_COLORS.length]
  return (
    <g transform={`scale(${scale})`}>
      <g className="opacity-60 dark:opacity-100">
        <circle
          className="christmas-halo"
          style={{ animationDelay: delay }}
          cy="7"
          r="8"
          fill={`url(#${glowId}-${colorIndex % CHRISTMAS_BULB_COLORS.length})`}
        />
      </g>
      <rect x="-1.4" y="0" width="2.8" height="3.6" rx="0.6" fill="#14532D" />
      <g className="christmas-bulb" style={{ animationDelay: delay }}>
        <path
          d="M-2.2 3.3C-3.2 5.4-2.7 8.6 0 10.9 2.7 8.6 3.2 5.4 2.2 3.3Z"
          fill={color}
        />
        <ellipse
          cx="-0.9"
          cy="5.8"
          rx="0.55"
          ry="1.4"
          fill="#FFFFFF"
          fillOpacity="0.6"
        />
      </g>
    </g>
  )
}

/** A scalloped string of C9 lights hanging under the header; parent must be `relative`. */
export function ChristmasLights() {
  const glowId = useId().replace(/:/g, '')

  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-full h-0 text-foreground"
      aria-hidden
    >
      <ChristmasBulbGlows id={glowId} />
      <div className="absolute inset-x-0 top-0 flex h-9 overflow-hidden">
        {Array.from({ length: SPANS }, (_, span) => (
          <svg
            key={span}
            width={SPAN}
            height="36"
            viewBox={`0 0 ${SPAN} 36`}
            className="shrink-0"
          >
            <path
              d={`M0 1Q${SPAN / 2} ${DIP} ${SPAN} 1`}
              fill="none"
              stroke="currentColor"
              strokeOpacity="0.35"
              strokeWidth="0.8"
            />
            <circle
              cx="0"
              cy="1"
              r="1.3"
              fill="currentColor"
              fillOpacity="0.35"
            />
            {BULBS.map(([x, y], index) => {
              const bulb = span * BULBS_PER_SPAN + index
              return (
                <g
                  key={index}
                  transform={`translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${TILTS[bulb % TILTS.length]})`}
                >
                  <ChristmasBulb
                    glowId={glowId}
                    colorIndex={bulb}
                    delay={`${(bulb * -0.16).toFixed(2)}s`}
                  />
                </g>
              )
            })}
          </svg>
        ))}
      </div>
    </div>
  )
}
