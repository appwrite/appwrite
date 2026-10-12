import { Puzzle } from 'lucide-react'
import { getIntegrationIconPath } from '@/lib/integrations/icons'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

type IntegrationIconProps = {
  slug: string
  vendor?: string
  alt?: string
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-8 w-8',
  md: 'h-10 w-10',
  lg: 'h-12 w-12',
}

const iconSizeClasses = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-6 w-6',
}

export function IntegrationIcon({
  slug,
  vendor,
  alt,
  className,
  size = 'md',
}: IntegrationIconProps) {
  const iconPath = getIntegrationIconPath(slug, vendor)
  const label = alt ?? vendor ?? slug
  const containerClass = sizeClasses[size]
  const iconClass = iconSizeClasses[size]

  if (iconPath) {
    return (
      <div
        className={cn(
          'flex shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40 p-1.5',
          containerClass,
          className,
        )}
      >
        <img
          src={iconPath}
          alt={label}
          className={cn(iconClass, PUBLIC_ICON_MUTED_CLASSES)}
          loading="lazy"
        />
      </div>
    )
  }

  return (
    <div
      className={cn(
        'flex shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40',
        containerClass,
        className,
      )}
    >
      <Puzzle className={cn(iconClass, 'text-muted-foreground')} aria-hidden />
    </div>
  )
}
