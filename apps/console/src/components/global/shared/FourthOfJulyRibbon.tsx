import { useId } from 'react'

const STAR =
  'M0-1 .24-.31.95-.31.38.12.59.81 0 .38-.59.81-.38.12-.95-.31-.24-.31Z'

/** Stars-and-stripes ribbon across the top of the terminal, with a passing sheen. */
export function FourthOfJulyRibbon() {
  const id = useId().replace(/:/g, '')

  return (
    <div className="relative h-4 shrink-0 overflow-hidden" aria-hidden>
      <svg className="absolute inset-x-0 top-0 h-2.5 w-full bg-[#0A3161]">
        <defs>
          <pattern id={id} width="14" height="10" patternUnits="userSpaceOnUse">
            <path
              transform="translate(7 5) scale(2.2)"
              d={STAR}
              fill="#FFFFFF"
            />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill={`url(#${id})`} />
      </svg>
      <div
        className="absolute inset-x-0 bottom-0 h-1.5"
        style={{
          backgroundImage:
            'linear-gradient(to bottom, #B31942 0 33%, #FFFFFF 33% 66%, #B31942 66%)',
        }}
      />
      <div className="july-sheen absolute inset-y-0 start-0 w-1/4 bg-gradient-to-r from-transparent via-white/25 to-transparent" />
    </div>
  )
}

/** Short rays for one crackle; the three layers take turns so the sparks never repeat. */
const SPARK_LAYERS = [
  [0, 33, 71, 118, 160, 205, 244, 290, 331],
  [16, 54, 97, 139, 184, 226, 268, 311, 350],
  [8, 45, 84, 128, 172, 214, 256, 300, 340],
] as const

/** A lit sparkler stuck in the terminal's bottom corner; parent must be `relative`. */
export function FourthOfJulySparkler() {
  const id = useId().replace(/:/g, '')

  return (
    <svg
      viewBox="0 0 64 64"
      className="pointer-events-none absolute bottom-1 end-3 z-20 size-16 overflow-visible rtl:-scale-x-100"
      aria-hidden
    >
      <defs>
        <radialGradient id={id}>
          <stop offset="0%" stopColor="#FEF3C7" stopOpacity="0.9" />
          <stop offset="35%" stopColor="#FBBF24" stopOpacity="0.35" />
          <stop offset="100%" stopColor="#FBBF24" stopOpacity="0" />
        </radialGradient>
      </defs>
      <path
        d="M50 62 36 32"
        stroke="#9CA3AF"
        strokeWidth="1.4"
        strokeLinecap="round"
      />
      <path
        d="M39.6 39.8 34 28"
        stroke="#57534E"
        strokeWidth="2.6"
        strokeLinecap="round"
      />
      <g transform="translate(34 28)">
        <circle className="july-flicker" r="16" fill={`url(#${id})`} />
        {SPARK_LAYERS.map((angles, layer) => (
          <g
            key={layer}
            className="july-crackle"
            style={{ animationDelay: `${layer * -0.12}s` }}
            stroke={layer === 1 ? '#FFFFFF' : '#FDE68A'}
            strokeWidth="0.7"
            strokeLinecap="round"
            fill="none"
          >
            {angles.map((angle, index) => {
              const length = 9 + ((index * 7 + layer * 3) % 9)
              return (
                <path
                  key={angle}
                  transform={`rotate(${angle})`}
                  d={`M3 0H${length}l2.4-1.6M${length - 2} 0l2 1.8`}
                />
              )
            })}
          </g>
        ))}
        <circle r="2.2" fill="#FFFBEB" />
        {[0, -0.5, -1, -1.5].map((delay, index) => (
          <circle
            key={delay}
            className="july-ember"
            style={{ animationDelay: `${delay}s` }}
            cx={(index - 1.5) * 3}
            cy="3"
            r="0.6"
            fill="#FDBA74"
          />
        ))}
      </g>
    </svg>
  )
}
