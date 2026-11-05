import { useId } from 'react'
import { cn } from '@/lib/utils'

type SchemaBlueprintMatProps = {
  className?: string
  /** Tighter dot spacing for compact panels (e.g. marketing mocks). */
  density?: 'default' | 'dense'
}

const DOT_DENSITY = {
  default: { spacing: 40, radius: 2.5 },
  dense: { spacing: 22, radius: 1.75 },
} as const

/**
 * Infinite blueprint dot mat used by database schema / browser visualizers.
 * Pattern id is unique per mount (React `useId`) so multiple instances never clash.
 */
export function SchemaBlueprintMat({
  className,
  density = 'default',
}: SchemaBlueprintMatProps) {
  const reactId = useId()
  const patternId = `schema-blueprint-dots-${reactId.replace(/:/g, '')}`
  const { spacing, radius } = DOT_DENSITY[density]

  return (
    <svg
      className={cn('absolute pointer-events-none', className)}
      style={{
        left: '-5000px',
        top: '-5000px',
        width: '10000px',
        height: '10000px',
        zIndex: 0,
      }}
      aria-hidden
    >
      <defs>
        <pattern
          id={patternId}
          width={spacing}
          height={spacing}
          patternUnits="userSpaceOnUse"
        >
          <circle
            cx="0"
            cy="0"
            r={radius}
            className="fill-foreground/20 dark:fill-foreground/30"
          />
        </pattern>
      </defs>
      <rect width="100%" height="100%" fill={`url(#${patternId})`} />
    </svg>
  )
}
