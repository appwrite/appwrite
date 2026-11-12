import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

/** Paths from `public/icons/graphql.svg`. */
const GRAPHQL_MARK = (
  <g fill="currentColor">
    <rect
      x="122"
      y="-0.4"
      transform="matrix(-0.866 -0.5 0.5 -0.866 163.3196 363.3136)"
      width="16.6"
      height="320.3"
    />
    <rect x="39.8" y="272.2" width="320.3" height="16.6" />
    <rect
      x="37.9"
      y="312.2"
      transform="matrix(-0.866 -0.5 0.5 -0.866 83.0693 663.3409)"
      width="185"
      height="16.6"
    />
    <rect
      x="177.1"
      y="71.1"
      transform="matrix(-0.866 -0.5 0.5 -0.866 463.3409 283.0693)"
      width="185"
      height="16.6"
    />
    <rect
      x="122.1"
      y="-13"
      transform="matrix(-0.5 -0.866 0.866 -0.5 126.7903 232.1221)"
      width="16.6"
      height="185"
    />
    <rect
      x="109.6"
      y="151.6"
      transform="matrix(-0.5 -0.866 0.866 -0.5 266.0828 473.3766)"
      width="320.3"
      height="16.6"
    />
    <rect x="52.5" y="107.5" width="16.6" height="185" />
    <rect x="330.9" y="107.5" width="16.6" height="185" />
    <rect
      x="262.4"
      y="240.1"
      transform="matrix(-0.5 -0.866 0.866 -0.5 126.7953 714.2875)"
      width="14.5"
      height="160.9"
    />
    <path d="M369.5,297.9c-9.6,16.7-31,22.4-47.7,12.8c-16.7-9.6-22.4-31-12.8-47.7c9.6-16.7,31-22.4,47.7-12.8C373.5,259.9,379.2,281.2,369.5,297.9" />
    <path d="M90.9,137c-9.6,16.7-31,22.4-47.7,12.8c-16.7-9.6-22.4-31-12.8-47.7c9.6-16.7,31-22.4,47.7-12.8C94.8,99,100.5,120.3,90.9,137" />
    <path d="M30.5,297.9c-9.6-16.7-3.9-38,12.8-47.7c16.7-9.6,38-3.9,47.7,12.8c9.6,16.7,3.9,38-12.8,47.7C61.4,320.3,40.1,314.6,30.5,297.9" />
    <path d="M309.1,137c-9.6-16.7-3.9-38,12.8-47.7c16.7-9.6,38-3.9,47.7,12.8c9.6,16.7,3.9,38-12.8,47.7C340.1,159.4,318.7,153.7,309.1,137" />
    <path d="M200,395.8c-19.3,0-34.9-15.6-34.9-34.9c0-19.3,15.6-34.9,34.9-34.9c19.3,0,34.9,15.6,34.9,34.9C234.9,380.1,219.3,395.8,200,395.8" />
    <path d="M200,74c-19.3,0-34.9-15.6-34.9-34.9c0-19.3,15.6-34.9,34.9-34.9c19.3,0,34.9,15.6,34.9,34.9C234.9,58.4,219.3,74,200,74" />
  </g>
)

type GraphqlIconProps = {
  className?: string
  /**
   * `brand` loads the SVG asset with muted img styling.
   * `nav` renders inline for side nav (filled mark toned down to match Lucide strokes).
   */
  variant?: 'brand' | 'nav'
}

/** GraphQL mark from `public/icons/graphql.svg`. */
export function GraphqlIcon({
  className,
  variant = 'brand',
}: GraphqlIconProps) {
  if (variant === 'nav') {
    return (
      <svg
        viewBox="0 0 400 400"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className={cn(
          'h-4 w-4 shrink-0 opacity-[0.55] dark:opacity-[0.72] group-hover:opacity-100 group-focus-visible:opacity-100',
          className,
        )}
        aria-hidden
      >
        {GRAPHQL_MARK}
      </svg>
    )
  }

  return (
    <img
      src="/icons/graphql.svg"
      alt=""
      className={cn('h-4 w-4 shrink-0', PUBLIC_ICON_MUTED_CLASSES, className)}
      aria-hidden
    />
  )
}
