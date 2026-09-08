import { Globe } from 'lucide-react'
import { getFrameworkIconFile } from '@/lib/frameworks'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

export interface FrameworkIconProps {
  framework: string | null | undefined
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-6 w-6',
}

/**
 * FrameworkIcon component that displays framework icons based on framework ID.
 * Uses icon mapping from @/lib/frameworks (single source for framework config).
 */
export function FrameworkIcon({
  framework,
  className,
  size = 'md',
}: FrameworkIconProps) {
  if (!framework || typeof framework !== 'string') {
    return <Globe className={cn(sizeClasses[size], className)} />
  }

  const iconFile = getFrameworkIconFile(framework)
  const sizeClass = sizeClasses[size as keyof typeof sizeClasses]

  if (iconFile) {
    return (
      <img
        src={`/icons/${iconFile}`}
        alt={framework}
        className={cn(sizeClass, PUBLIC_ICON_MUTED_CLASSES, className)}
      />
    )
  }

  return <Globe className={cn(sizeClass, className)} />
}
