import { Package } from 'lucide-react'
import { cn } from '@/lib/utils'

const PACKAGE_MANAGER_ICON_MAP: Record<string, string> = {
  npm: 'npm.svg',
  bun: 'bun.svg',
  pnpm: 'pnpm.svg',
}

export interface PackageManagerIconProps {
  packageManager: string | null | undefined
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-6 w-6',
}

/**
 * PackageManagerIcon displays the icon for npm, bun, pnpm, etc.
 * Icons are in /public/icons/ (npm.svg, bun.svg, pnpm.svg).
 */
export function PackageManagerIcon({
  packageManager,
  className,
  size = 'md',
}: PackageManagerIconProps) {
  if (!packageManager || typeof packageManager !== 'string') {
    return <Package className={cn(sizeClasses[size], className)} />
  }

  const iconFile = PACKAGE_MANAGER_ICON_MAP[packageManager.toLowerCase()]
  const sizeClass = sizeClasses[size as keyof typeof sizeClasses]

  if (iconFile) {
    return (
      <img
        src={`/icons/${iconFile}`}
        alt={packageManager}
        className={cn(sizeClass, 'brightness-0 dark:brightness-100', className)}
      />
    )
  }

  return <Package className={cn(sizeClass, className)} />
}
