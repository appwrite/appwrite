import { cn } from '@/lib/utils'
import { LEGACY_ICON_SRC, LEGACY_LOGO_SRC } from '@/lib/legacy-theme-assets'

export function LegacyAppwriteIcon({
  className,
}: {
  className?: string
}) {
  return (
    <img
      src={LEGACY_ICON_SRC}
      alt=""
      aria-hidden
      className={cn('h-6 w-auto shrink-0', className)}
    />
  )
}

export function LegacyAppwriteLogo({
  className,
  'aria-label': ariaLabel = 'Appwrite',
}: {
  className?: string
  'aria-label'?: string
}) {
  return (
    <img
      src={LEGACY_LOGO_SRC}
      alt={ariaLabel}
      className={cn('h-6 w-auto shrink-0', className)}
    />
  )
}
