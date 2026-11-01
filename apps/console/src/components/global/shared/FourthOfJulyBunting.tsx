const SPAN = 168
const SPANS = 24
const RED = '#B31942'
const NAVY = '#0A3161'
/** Swag band edges, as how far each one dips at the middle. */
const DIPS = [0, 5, 9, 13] as const
/** Fan rings from the outside in. */
const RINGS = [
  [15, RED],
  [12.6, '#FFFFFF'],
  [10.4, RED],
  [8.2, '#FFFFFF'],
  [6.2, NAVY],
] as const

function band(from: number, to: number) {
  return `M0 0Q${SPAN / 2} ${from * 2} ${SPAN} 0Q${SPAN / 2} ${to * 2} 0 0Z`
}

function halfDisc(radius: number) {
  return `M${-radius} 0A${radius} ${radius} 0 0 0 ${radius} 0Z`
}

/** Stars along the middle of the navy band. */
const SWAG_STARS = [0.18, 0.32, 0.5, 0.68, 0.82].map((t) => [
  t * SPAN,
  DIPS[1] * 2 * t * (1 - t),
])

function Star({ x, y, size }: { x: number; y: number; size: number }) {
  return (
    <path
      transform={`translate(${x} ${y}) scale(${size})`}
      d="M0-1 .24-.31.95-.31.38.12.59.81 0 .38-.59.81-.38.12-.95-.31-.24-.31Z"
      fill="#FFFFFF"
    />
  )
}

/** Pleated half-fan rosette pinned to the header edge at the origin. */
function Fan() {
  return (
    <g>
      {RINGS.map(([radius, color]) => (
        <path key={radius} d={halfDisc(radius)} fill={color} />
      ))}
      <g stroke="#000" strokeOpacity="0.14" strokeWidth="0.5">
        {[20, 40, 60, 80, 100, 120, 140, 160].map((angle) => (
          <path
            key={angle}
            d={`M0 0L${(15 * Math.cos((angle * Math.PI) / 180)).toFixed(2)} ${(15 * Math.sin((angle * Math.PI) / 180)).toFixed(2)}`}
          />
        ))}
      </g>
      <Star x={0} y={3} size={1.6} />
      <Star x={-3.2} y={1.4} size={0.9} />
      <Star x={3.2} y={1.4} size={0.9} />
      <circle r="1.6" fill={RED} stroke="#FFFFFF" strokeWidth="0.5" />
    </g>
  )
}

/** Pleated fan bunting with draped swags, pinned along the bottom of the header; parent must be `relative`. */
export function FourthOfJulyBunting() {
  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-full h-0"
      aria-hidden
    >
      <div className="absolute inset-x-0 top-0 flex h-8 overflow-hidden">
        {Array.from({ length: SPANS }, (_, span) => (
          <svg
            key={span}
            width={SPAN}
            height="32"
            viewBox={`0 0 ${SPAN} 32`}
            overflow="visible"
            className="shrink-0"
          >
            <g
              className="july-billow"
              style={{ animationDelay: `${(span % 4) * -0.9}s` }}
            >
              <path d={band(DIPS[0], DIPS[1])} fill={NAVY} />
              <path d={band(DIPS[1], DIPS[2])} fill="#FFFFFF" />
              <path d={band(DIPS[2], DIPS[3])} fill={RED} />
              <path
                d={`M0 0Q${SPAN / 2} ${DIPS[3] * 2} ${SPAN} 0`}
                fill="none"
                stroke="#000"
                strokeOpacity="0.2"
                strokeWidth="0.6"
              />
              <path
                d={`M0 0Q${SPAN / 2} ${DIPS[1] * 2 + 1} ${SPAN} 0M0 0Q${SPAN / 2} ${DIPS[2] * 2 + 1} ${SPAN} 0`}
                fill="none"
                stroke="#000"
                strokeOpacity="0.08"
                strokeWidth="0.5"
              />
              {SWAG_STARS.map(([x, y]) => (
                <Star key={x} x={x} y={y} size={1.1} />
              ))}
            </g>
            <Fan />
          </svg>
        ))}
      </div>
    </div>
  )
}
