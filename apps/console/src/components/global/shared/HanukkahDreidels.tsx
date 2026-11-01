const DREIDEL_COLORS = ['#1D4ED8', '#C4930A', '#2563EB', '#E8C547'] as const
const DREIDEL_COUNT = 11

function DreidelGlyph({ fill }: { fill: string }) {
  return (
    <svg viewBox="-8 -18 16 32" className="h-5 w-4" aria-hidden>
      <rect x="-1" y="-17" width="2" height="5" rx="0.6" fill="#C4930A" />
      <path d="M-6.5-12h13l-1.8 12h-9.4Z" fill={fill} />
      <path d="M-4.6 0 0 9 4.6 0Z" fill={fill} />
      <circle cx="0" cy="-5" r="1.5" fill="#FDE68A" />
    </svg>
  )
}

/** Sevivonim hanging from the header; parent must be `relative`. */
export function HanukkahDreidels() {
  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-full h-0 text-foreground"
      aria-hidden
    >
      <svg
        viewBox="0 0 220 16"
        preserveAspectRatio="none"
        className="absolute inset-x-0 top-0 block h-3 w-full"
      >
        <path
          d="M0 2 Q 55 12 110 3 T 220 2"
          fill="none"
          stroke="currentColor"
          strokeOpacity="0.28"
          strokeWidth="0.7"
        />
      </svg>
      <div className="absolute inset-x-3 top-0 flex h-8 justify-between">
        {Array.from({ length: DREIDEL_COUNT }, (_, index) => {
          const sag = Math.sin((index / (DREIDEL_COUNT - 1)) * Math.PI) * 6
          return (
            <span
              key={index}
              className="flex flex-col items-center"
              style={{ transform: `translateY(${sag}px)` }}
            >
              <span className="h-2 w-px bg-current opacity-30" />
              <span
                className="hanukkah-spin"
                style={{ animationDelay: `${(index % 5) * -0.55}s` }}
              >
                <DreidelGlyph
                  fill={DREIDEL_COLORS[index % DREIDEL_COLORS.length]}
                />
              </span>
            </span>
          )
        })}
      </div>
    </div>
  )
}
