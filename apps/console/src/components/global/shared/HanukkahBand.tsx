import { useId } from 'react'
import { Dreidel } from '@/components/global/shared/HanukkahDreidel'
import { useHanukkahNight } from '@/hooks/use-hanukkah'
import { HANUKKAH_NIGHTS, isHanukkahBranchLit } from '@/lib/hanukkah'
import { useT } from '@/lib/i18n/translate'

const BRANCH_XS = [-14, -10.5, -7, -3.5, 3.5, 7, 10.5, 14] as const

/** Hanukkiah glyph sized for the band, lit for tonight. */
function MiniHanukkiah({ night }: { night: number }) {
  const flame = (x: number, y: number, delay: string) => (
    <g key={x} transform={`translate(${x} ${y})`}>
      <circle r="2.4" fill="#FCD34D" fillOpacity="0.3" />
      <ellipse
        className="hanukkah-flame"
        style={{ animationDelay: delay }}
        cy="-0.6"
        rx="0.9"
        ry="1.5"
        fill="#FDE68A"
      />
    </g>
  )

  return (
    <svg viewBox="-17 -12 34 13" className="h-3.5 w-auto shrink-0">
      <g fill="#E8C547">
        <rect x="-15" y="-4.6" width="30" height="1" rx="0.5" />
        <rect x="-0.6" y="-4.6" width="1.2" height="5" />
        <rect x="-4" y="0" width="8" height="0.9" rx="0.4" />
        {BRANCH_XS.map((x) => (
          <rect key={x} x={x - 0.4} y="-7" width="0.8" height="2.6" />
        ))}
        <rect x="-0.4" y="-9" width="0.8" height="4.6" />
      </g>
      {BRANCH_XS.map((x, index) =>
        isHanukkahBranchLit(index, night)
          ? flame(x, -7.6, `${index * -0.3}s`)
          : null,
      )}
      {night > 0 ? flame(0, -9.6, '-0.5s') : null}
    </svg>
  )
}

/** Deep blue band across the top of the terminal with tonight's candles. */
export function HanukkahBand() {
  const t = useT()
  const id = useId().replace(/:/g, '')
  const night = useHanukkahNight() ?? HANUKKAH_NIGHTS

  return (
    <div
      className="relative flex h-5 shrink-0 items-center justify-center gap-2 overflow-hidden bg-gradient-to-b from-[#1E3A8A] to-[#172554] text-[10px] font-semibold uppercase leading-none tracking-[0.2em] text-[#FDE68A] rtl:tracking-normal"
      aria-hidden
    >
      <svg className="absolute inset-0 h-full w-full opacity-20">
        <defs>
          <pattern id={id} width="18" height="20" patternUnits="userSpaceOnUse">
            <path
              d="M9 5.5 12.5 11.5H5.5ZM9 13.5 12.5 7.5H5.5Z"
              fill="none"
              stroke="#93C5FD"
              strokeWidth="0.5"
            />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill={`url(#${id})`} />
      </svg>
      <span className="absolute inset-x-0 top-0 h-px bg-[#E8C547]/50" />
      <span className="absolute inset-x-0 bottom-0 h-px bg-[#E8C547]/30" />
      <MiniHanukkiah night={night} />
      <span className="relative">{t('Happy Hanukkah')}</span>
      <MiniHanukkiah night={night} />
    </div>
  )
}

/** Dreidel spinning in the terminal's bottom corner until it falls on פ; parent must be `relative`. */
export function HanukkahCornerDreidel() {
  return (
    <svg
      viewBox="-16 -34 32 36"
      className="pointer-events-none absolute bottom-3 end-6 z-20 h-12 w-auto overflow-visible drop-shadow-[0_2px_3px_rgba(0,0,0,0.4)]"
      aria-hidden
    >
      <ellipse cx="0" cy="0.4" rx="6" ry="1.3" fill="#000" fillOpacity="0.35" />
      <Dreidel topple delay="-3s" />
    </svg>
  )
}
