import { forwardRef } from 'react'
import { Icon, type LucideProps } from 'lucide-react'
import { elephant } from '@lucide/lab'
import { cn } from '@/lib/utils'

/** PostgreSQL mascot (Slonik) – from @lucide/lab, matches Lucide stroke style. */
export function PostgresElephantIcon(props: LucideProps) {
  return <Icon iconNode={elephant} {...props} />
}

/**
 * MySQL dolphin mascot – stroke outline adapted for 24px Lucide rendering.
 * Icon Park outline (Apache-2.0), scaled to 48×48 viewBox for correct stroke weight.
 */
export const MySQLDolphinIcon = forwardRef<SVGSVGElement, LucideProps>(
  (
    {
      color = 'currentColor',
      size = 24,
      strokeWidth = 4,
      absoluteStrokeWidth,
      className,
      ...rest
    },
    ref,
  ) => {
    const resolvedStrokeWidth = absoluteStrokeWidth
      ? (Number(strokeWidth) * 24) / Number(size)
      : strokeWidth

    return (
      <svg
        ref={ref}
        xmlns="http://www.w3.org/2000/svg"
        width={size}
        height={size}
        viewBox="0 0 48 48"
        fill="none"
        stroke={color}
        strokeWidth={resolvedStrokeWidth}
        strokeLinecap="round"
        strokeLinejoin="round"
        className={cn('lucide', className)}
        {...rest}
      >
        <path
          d="M24 7C14.23 1.613 9.686 8.632 8 11c-5.664 1.218-2.854 3.324-1 4c1.214.406 4.146 1.323 6 2c.405 3.248 1.663 6.154 2 7c0-.812 1.326-3.647 2-5c8.092 3.248 13.797 11.602 17 16c-1.214 2.436-2.494 6.308-3 8l6-3l7 2c0-3.248-4.145-6.647-6-8c.81-12.992-5.29-20.8-9-23c.405-1.624 1.157-4.323 2-5c-3.237-1.624-5.82.154-7 1"
        />
        <circle cx="16" cy="11" r="2" fill="currentColor" stroke="none" />
      </svg>
    )
  },
)
MySQLDolphinIcon.displayName = 'MySQLDolphinIcon'
