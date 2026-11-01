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
