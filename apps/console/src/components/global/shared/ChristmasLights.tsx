const BULB_COLORS = ['#DC2626', '#16A34A', '#EAB308', '#F8FAFC'] as const
const BULB_COUNT = 22

/** String of lights hanging from the header; parent must be `relative`. */
export function ChristmasLights() {
  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-full h-0 text-foreground"
      aria-hidden
    >
      <svg
        viewBox="0 0 220 16"
        preserveAspectRatio="none"
        className="absolute inset-x-0 top-0 block h-4 w-full"
      >
        <path
          d="M0 2 Q 55 14 110 3 T 220 2"
          fill="none"
          stroke="currentColor"
          strokeOpacity="0.28"
          strokeWidth="0.7"
        />
      </svg>
      <div className="absolute inset-x-2 top-0 flex h-5 justify-between">
        {Array.from({ length: BULB_COUNT }, (_, index) => {
          const sag = Math.sin((index / (BULB_COUNT - 1)) * Math.PI) * 7
          return (
            <span
              key={index}
              className="flex flex-col items-center"
              style={{ transform: `translateY(${sag}px)` }}
            >
              <span className="h-1.5 w-px bg-current opacity-30" />
              <span
                className="christmas-twinkle h-[5px] w-[5px] rounded-full"
                style={{
                  backgroundColor: BULB_COLORS[index % BULB_COLORS.length],
                  animationDelay: `${(index % 7) * -0.45}s`,
                  boxShadow: `0 0 4px ${BULB_COLORS[index % BULB_COLORS.length]}`,
                }}
              />
            </span>
          )
        })}
      </div>
    </div>
  )
}
