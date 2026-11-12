import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

/** Paths from `public/icons/terraform.svg`. */
const TERRAFORM_MARK = (
  <g transform="scale(0.1875)" fill="currentColor" fillRule="evenodd">
    <path d="M77.941 44.5v36.836L46.324 62.918V26.082zm0 0" />
    <path d="M81.41 81.336l31.633-18.418V26.082L81.41 44.5zm0 0" />
    <path
      d="M11.242 42.36L42.86 60.776V23.941L11.242 5.523zm0 0M77.941 85.375L46.324 66.957v36.82l31.617 18.418zm0 0"
    />
  </g>
)

type TerraformIconProps = {
  className?: string
  /**
   * `brand` loads the SVG asset with muted img styling.
   * `nav` renders inline for side nav (filled mark toned down to match Lucide strokes).
   */
  variant?: 'brand' | 'nav'
}

/** Terraform mark from `public/icons/terraform.svg`. */
export function TerraformIcon({
  className,
  variant = 'brand',
}: TerraformIconProps) {
  if (variant === 'nav') {
    return (
      <svg
        viewBox="0 0 24 24"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className={cn(
          'h-4 w-4 shrink-0 opacity-[0.55] dark:opacity-[0.72] group-hover:opacity-100 group-focus-visible:opacity-100',
          className,
        )}
        aria-hidden
      >
        {TERRAFORM_MARK}
      </svg>
    )
  }

  return (
    <img
      src="/icons/terraform.svg"
      alt=""
      className={cn('h-4 w-4 shrink-0', PUBLIC_ICON_MUTED_CLASSES, className)}
      aria-hidden
    />
  )
}
