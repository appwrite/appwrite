import { Dreidel } from '@/components/global/shared/HanukkahDreidel'

const HEXAGRAM = 'M0-8.5 7.4 4.25H-7.4ZM0 8.5 7.4-4.25H-7.4Z'

type Ornament = {
  kind: 'star' | 'dreidel'
  /** Distance from the inline end of the header, in px. */
  end: number
  /** String length, in px. */
  length: number
  size: number
  delay: number
}

const ORNAMENTS: readonly Ornament[] = [
  { kind: 'star', end: 26, length: 30, size: 18, delay: 0 },
  { kind: 'dreidel', end: 60, length: 54, size: 30, delay: -1.1 },
  { kind: 'star', end: 96, length: 16, size: 22, delay: -2.3 },
  { kind: 'star', end: 128, length: 46, size: 14, delay: -0.7 },
  { kind: 'dreidel', end: 158, length: 22, size: 22, delay: -1.8 },
]

function MagenDavid({ size, delay }: { size: number; delay: number }) {
  return (
    <svg
      viewBox="-10 -10 20 20"
      width={size}
      height={size}
      className="hanukkah-turn -mt-0.5 overflow-visible"
      style={{ animationDelay: `${delay}s` }}
    >
      <g fill="none" strokeLinejoin="round">
        <path d={HEXAGRAM} stroke="#1D4ED8" strokeWidth="2.6" />
        <path d={HEXAGRAM} stroke="#DBEAFE" strokeWidth="0.9" />
      </g>
      <circle
        className="hanukkah-twinkle"
        style={{ animationDelay: `${delay}s` }}
        cx="-2.6"
        cy="-5.2"
        r="0.9"
        fill="#FFFFFF"
      />
    </svg>
  )
}

/** Stars of David and dreidels hanging at different lengths in the header's end corner; parent must be `relative`. */
export function HanukkahOrnaments() {
  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-full h-0 text-foreground"
      aria-hidden
    >
      {ORNAMENTS.map((ornament) => (
        <span
          key={ornament.end}
          className="hanukkah-sway absolute top-0 flex flex-col items-center"
          style={{
            insetInlineEnd: ornament.end,
            animationDelay: `${ornament.delay}s`,
            animationDuration: `${3 + (ornament.end % 5) * 0.4}s`,
          }}
        >
          <span
            className="w-px bg-current opacity-30"
            style={{ height: ornament.length }}
          />
          {ornament.kind === 'star' ? (
            <MagenDavid size={ornament.size} delay={ornament.delay} />
          ) : (
            <svg
              viewBox="-8 -31 16 32"
              height={ornament.size}
              width={ornament.size / 2}
              className="overflow-visible"
            >
              <Dreidel delay={`${ornament.delay}s`} />
            </svg>
          )}
        </span>
      ))}
    </div>
  )
}
