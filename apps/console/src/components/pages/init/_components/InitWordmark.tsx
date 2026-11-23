import { cn } from '@/lib/utils'

/** Aeonik Pro Black Italic @ 100px, -0.025em tracking; each glyph is local + horizontal translate. */
const INIT_WORDMARK_VIEWBOX = '-1 -82.4 185 92.4'

const INIT_WORDMARK_LETTERS = [
  {
    x: 0,
    d: 'M19.4 -62.6Q15.4 -62.6 12.9 -65.05Q10.4 -67.5 10.4 -71.3Q10.4 -75.6 13.4 -78.5Q16.4 -81.4 20.7 -81.4Q24.7 -81.4 27.2 -78.95Q29.7 -76.5 29.7 -72.7Q29.7 -68.4 26.7 -65.5Q23.7 -62.6 19.4 -62.6ZM0 -7.2 9.1 -58.8H26.5L17.4 -7.2Z',
  },
  {
    x: 24,
    d: 'M39.3 -59.4Q46.9 -59.4 52.3 -55.15Q57.7 -50.9 57.7 -41.4Q57.7 -38 57 -34.2L52.2 -7.2H34.9L39.7 -34.3Q40.2 -37.3 40.2 -39.1Q40.2 -45.6 33.2 -45.6Q24.1 -45.6 21.9 -33.3L17.3 -7.2H0L9.1 -58.8H24.2L24.4 -53.3Q30.8 -59.4 39.3 -59.4Z',
  },
  {
    x: 82,
    d: 'M19.4 -62.6Q15.4 -62.6 12.9 -65.05Q10.4 -67.5 10.4 -71.3Q10.4 -75.6 13.4 -78.5Q16.4 -81.4 20.7 -81.4Q24.7 -81.4 27.2 -78.95Q29.7 -76.5 29.7 -72.7Q29.7 -68.4 26.7 -65.5Q23.7 -62.6 19.4 -62.6ZM0 -7.2 9.1 -58.8H26.5L17.4 -7.2Z',
  },
  {
    x: 106,
    d: 'M6.3 -23.3 10.1 -44.5H2L4.5 -58.8H12.6L15.2 -73.3H32.5L29.9 -58.8H41L38.5 -44.5H27.4L24.1 -26.2Q23.9 -25 23.9 -24.2Q23.9 -21.5 27.5 -21.5H34.4L31.9 -7.2H20.5Q13.6 -7.2 9.75 -10.3Q5.9 -13.4 5.9 -19.4Q5.9 -20.5 6.3 -23.3Z',
  },
] as const

const INIT_WORDMARK_UNDERSCORE = {
  x: 141.7,
  d: 'M38.5 9H-2.9L-0.1 -6.6H41.3Z',
} as const

type InitWordmarkProps = {
  className?: string
  /** Ticket accent for the underscore; defaults to brand CTA. */
  accentColor?: string
  'aria-label'?: string
}

/**
 * init_ wordmark as outlined SVG paths (Aeonik Pro Black Italic + brand underscore).
 * Size with text utilities, e.g. `className="text-[clamp(24px,5vw,38px)] text-foreground"`.
 */
export function InitWordmark({
  className,
  accentColor = 'var(--brand-cta)',
  'aria-label': ariaLabel = 'init',
}: InitWordmarkProps) {
  return (
    <svg
      viewBox={INIT_WORDMARK_VIEWBOX}
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={cn('inline-block h-[1em] w-auto shrink-0 align-[-0.05em] leading-none', className)}
      role="img"
      aria-label={ariaLabel}
    >
      {INIT_WORDMARK_LETTERS.map((letter, index) => (
        <g key={`init-letter-${index}`} transform={`translate(${letter.x} 0)`}>
          <path fill="currentColor" d={letter.d} />
        </g>
      ))}
      <g transform={`translate(${INIT_WORDMARK_UNDERSCORE.x} 0)`}>
        <path fill={accentColor} d={INIT_WORDMARK_UNDERSCORE.d} />
      </g>
    </svg>
  )
}
