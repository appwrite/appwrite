import { useId } from 'react'
import {
  CHRISTMAS_BULB_COLORS,
  ChristmasBulbGlows,
} from '@/components/global/shared/ChristmasLights'

const SPAN = 72
const SPANS = 40
const ROPE = `M0 2Q${SPAN / 2} 16 ${SPAN} 2`
const BAUBLES = ['#DC2626', '#F59E0B', '#2563EB'] as const
const LIGHTS = [
  [12, 5.6],
  [36, 9],
  [60, 5.6],
] as const

/** Pine rope draped across the top of the terminal, with baubles and lights. */
export function ChristmasGarland() {
  const glowId = useId().replace(/:/g, '')

  return (
    <div className="relative z-10 h-2.5 shrink-0" aria-hidden>
      <ChristmasBulbGlows id={glowId} />
      <div className="pointer-events-none absolute inset-x-0 top-0 flex h-6 overflow-hidden">
        {Array.from({ length: SPANS }, (_, span) => {
          const bauble = BAUBLES[span % BAUBLES.length]
          return (
            <svg
              key={span}
              width={SPAN}
              height="24"
              viewBox={`0 0 ${SPAN} 24`}
              className="shrink-0"
            >
              <path d="M36 9v3.4" stroke="#A16207" strokeWidth="0.5" />
              <rect
                x="34.6"
                y="11.6"
                width="2.8"
                height="1.8"
                rx="0.4"
                fill="#CA8A04"
              />
              <circle cx="36" cy="16.8" r="3.6" fill={bauble} />
              <circle
                cx="36"
                cy="16.8"
                r="3.6"
                fill="#000"
                fillOpacity="0.18"
                style={{ clipPath: 'inset(0 0 0 50%)' }}
              />
              <ellipse
                cx="34.6"
                cy="15.4"
                rx="1"
                ry="1.4"
                fill="#FFFFFF"
                fillOpacity="0.55"
              />

              <path
                d={ROPE}
                fill="none"
                stroke="#14532D"
                strokeWidth="4.4"
                strokeLinecap="round"
              />
              <path
                d={ROPE}
                fill="none"
                stroke="#15803D"
                strokeWidth="5.8"
                strokeDasharray="0.7 1.5"
              />
              <path
                d={ROPE}
                fill="none"
                stroke="#166534"
                strokeWidth="4.8"
                strokeDasharray="0.6 1.3"
                strokeDashoffset="1"
              />
              <path
                d={ROPE}
                fill="none"
                stroke="#4ADE80"
                strokeOpacity="0.55"
                strokeWidth="2.4"
                strokeDasharray="0.4 2.8"
              />

              {LIGHTS.map(([x, y], index) => {
                const light = span * LIGHTS.length + index
                const delay = `${(light * -0.21).toFixed(2)}s`
                return (
                  <g key={index}>
                    <circle
                      className="christmas-halo opacity-60 dark:opacity-100"
                      style={{ animationDelay: delay }}
                      cx={x}
                      cy={y}
                      r="4.5"
                      fill={`url(#${glowId}-${light % CHRISTMAS_BULB_COLORS.length})`}
                    />
                    <circle
                      className="christmas-bulb"
                      style={{ animationDelay: delay }}
                      cx={x}
                      cy={y}
                      r="1.2"
                      fill={
                        CHRISTMAS_BULB_COLORS[
                          light % CHRISTMAS_BULB_COLORS.length
                        ]
                      }
                    />
                  </g>
                )
              })}
            </svg>
          )
        })}
      </div>
    </div>
  )
}

/** Satin ribbon tied in a bow across the terminal's bottom corner; parent must be `relative`. */
export function ChristmasBow() {
  return (
    <div
      dir="ltr"
      className="pointer-events-none absolute -right-12 bottom-7 z-20 flex h-4 w-56 -rotate-[32deg] items-center justify-center border-y border-[#FBBF24]/50 shadow-[0_1px_2px_rgba(0,0,0,0.3)]"
      style={{
        backgroundImage:
          'linear-gradient(to bottom, #7F1D1D, #B91C1C 25%, #EF4444 48%, #DC2626 55%, #B91C1C 78%, #7F1D1D)',
      }}
      aria-hidden
    >
      <svg
        viewBox="-22 -14 44 32"
        className="relative -top-1 h-12 w-16 overflow-visible drop-shadow-[0_2px_2px_rgba(0,0,0,0.4)]"
      >
        <path d="M-1.2 1.6-9.4 15l2.6-1.2 1 3.2 7.4-14Z" fill="#B91C1C" />
        <path d="M1.2 1.6 9.4 15l-2.6-1.2-1 3.2-7.4-14Z" fill="#991B1B" />
        <path
          d="M0 0C-6-10-20.5-11.4-19.5-3-18.6 3.4-8 3.2 0 0Z"
          fill="#E11D48"
        />
        <path
          d="M0 0C6-10 20.5-11.4 19.5-3 18.6 3.4 8 3.2 0 0Z"
          fill="#DC2626"
        />
        <path
          d="M0 0C-4.6-6.2-13.6-7.6-14-3.4-14.2-.4-7.4 1-0 0Z"
          fill="#7F1D1D"
          fillOpacity="0.55"
        />
        <path
          d="M0 0C4.6-6.2 13.6-7.6 14-3.4 14.2-.4 7.4 1 0 0Z"
          fill="#7F1D1D"
          fillOpacity="0.55"
        />
        <path
          d="M-3.6-4C-7.6-8.4-15.4-9.4-17.4-5.2M3.6-4C7.6-8.4 15.4-9.4 17.4-5.2"
          fill="none"
          stroke="#FECACA"
          strokeOpacity="0.7"
          strokeWidth="0.8"
          strokeLinecap="round"
        />
        <rect
          x="-3.6"
          y="-3.8"
          width="7.2"
          height="7.4"
          rx="2.2"
          fill="#B91C1C"
        />
        <path
          d="M-2-2.4h3"
          stroke="#FECACA"
          strokeOpacity="0.6"
          strokeWidth="0.7"
          strokeLinecap="round"
        />
      </svg>
    </div>
  )
}
