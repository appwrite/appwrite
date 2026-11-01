const PENNANT_COLORS = ['#B91C1C', '#F8FAFC', '#1D4ED8'] as const
const PENNANT_COUNT = 18

/** Red, white, and blue pennants hanging from the header; parent must be `relative`. */
export function FourthOfJulyBunting() {
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
          d="M0 1.5 Q 55 10 110 2 T 220 1.5"
          fill="none"
          stroke="currentColor"
          strokeOpacity="0.28"
          strokeWidth="0.7"
        />
      </svg>
      <div className="absolute inset-x-2 top-0 flex h-6 justify-between">
        {Array.from({ length: PENNANT_COUNT }, (_, index) => {
          const sag = Math.sin((index / (PENNANT_COUNT - 1)) * Math.PI) * 5
          return (
            <span
              key={index}
              className="flex flex-col items-center"
              style={{ transform: `translateY(${sag}px)` }}
            >
              <span className="h-1 w-px bg-current opacity-30" />
              <span
                className="july-sway h-3.5 w-2.5"
                style={{
                  animationDelay: `${(index % 6) * -0.4}s`,
                  backgroundColor:
                    PENNANT_COLORS[index % PENNANT_COLORS.length],
                  clipPath: 'polygon(0 0, 100% 0, 50% 100%)',
                  boxShadow:
                    index % 3 === 1
                      ? '0 0 3px rgba(248,250,252,0.35)'
                      : undefined,
                }}
              />
            </span>
          )
        })}
      </div>
    </div>
  )
}
