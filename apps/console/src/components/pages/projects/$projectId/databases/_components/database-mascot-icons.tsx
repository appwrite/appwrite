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

/** MongoDB leaf mascot – public icon adapted for 24px card rendering. */
export const MongoDbLeafIcon = forwardRef<SVGSVGElement, LucideProps>(
  ({ size = 24, className, ...rest }, ref) => {
    return (
      <svg
        ref={ref}
        xmlns="http://www.w3.org/2000/svg"
        width={size}
        height={size}
        viewBox="0 0 20 20"
        fill="none"
        className={cn('lucide', className)}
        {...rest}
      >
        <path
          d="M11.4882 2.99962C10.7691 2.10624 10.1499 1.19891 10.0234 1.01047C10.0101 0.99651 9.9901 0.99651 9.97679 1.01047C9.85028 1.19891 9.23107 2.10624 8.51199 2.99962C2.33987 11.2423 9.48409 16.805 9.48409 16.805L9.54401 16.8469C9.59727 17.7054 9.73043 18.9407 9.73043 18.9407H9.99676H10.2631C10.2631 18.9407 10.3963 17.7123 10.4495 16.8469L10.5094 16.798C10.5161 16.798 17.6603 11.2423 11.4882 2.99962ZM9.99676 16.6794C9.99676 16.6794 9.67717 16.3932 9.59061 16.2466V16.2327L9.97679 7.2571C9.97679 7.22918 10.0167 7.22918 10.0167 7.2571L10.4029 16.2327V16.2466C10.3164 16.3932 9.99676 16.6794 9.99676 16.6794Z"
          fill="currentColor"
        />
      </svg>
    )
  },
)
MongoDbLeafIcon.displayName = 'MongoDbLeafIcon'
