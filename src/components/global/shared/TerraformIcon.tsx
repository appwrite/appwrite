import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

/** Terraform mark from `public/icons/terraform.svg` (same pattern as other `/icons` assets). */
export function TerraformIcon({ className }: { className?: string }) {
  return (
    <img
      src="/icons/terraform.svg"
      alt=""
      className={cn('h-4 w-4 shrink-0', PUBLIC_ICON_MUTED_CLASSES, className)}
      aria-hidden
    />
  )
}
